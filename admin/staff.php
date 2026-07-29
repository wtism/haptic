<?php
// PHPエラーはログファイルへ記録（画面には出さない）
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_admin_errors.log');

require_once __DIR__ . '/auth.php';
requireLogin();

$db      = db();
$msg     = '';
$msgType = 'success';

$uploadDir     = __DIR__ . '/uploads/staff/';
$uploadUrlBase = '/admin/uploads/staff/';

function handlePhotoUpload(array $file, int $staffId): ?string
{
    global $uploadDir, $uploadUrlBase;
    if ($file['error'] !== UPLOAD_ERR_OK || !$file['size']) return null;
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'])) return null;
    $filename = 'staff_' . $staffId . '_' . time() . '.' . $ext;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
        // 絶対URLで返す
        $scheme = isset($_SERVER['HTTPS']) ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'haptic.irodori.tokyo';
        return $scheme . '://' . $host . $uploadUrlBase . $filename;
    }
    return null;
}

// 非管理者は自分のIDにリダイレクト
if (!isAdmin()) {
    $myStaffId = $_SESSION['staff_id'] ?? null;
    if (!$myStaffId) { header('Location: ' . adminUrl('dashboard.php')); exit; }

    // 自分のプロフィール編集のみ
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $photoUrl = $_POST['current_photo'] ?: null;
        if (!empty($_FILES['photo']['size'])) {
            $newUrl = handlePhotoUpload($_FILES['photo'], $myStaffId);
            if ($newUrl) $photoUrl = $newUrl;
        }
        $passwordHash = null;
        if (!empty($_POST['new_password'])) {
            if (strlen($_POST['new_password']) < 8) {
                $msg = 'パスワードは8文字以上にしてください'; $msgType = 'danger';
            } else {
                $passwordHash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
            }
        }
        if (!$msg) {
            $sql    = 'UPDATE staff SET photo_url=?';
            $params = [$photoUrl];
            if ($passwordHash) { $sql .= ', password_hash=?'; $params[] = $passwordHash; }
            $sql .= ' WHERE id=?'; $params[] = $myStaffId;
            $db->prepare($sql)->execute($params);
            auditLog('update', 'staff', $myStaffId, '自分のプロフィール更新');
            $msg = 'プロフィールを更新しました';
        }
    }

    $s = $db->prepare('SELECT s.*, r.name AS role_name FROM staff s LEFT JOIN staff_roles r ON s.role_id = r.id WHERE s.id = ?');
    $s->execute([$myStaffId]);
    $myStaff = $s->fetch();

    $pageTitle = 'マイプロフィール';
    include __DIR__ . '/_header.php';
?>
<div class="page-title">マイプロフィール</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<div style="max-width:500px;">
<div class="card">
    <div class="card-header">プロフィール</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="current_photo" value="<?= h($myStaff['photo_url'] ?? '') ?>">
            <div style="display:flex;align-items:center;gap:20px;margin-bottom:20px;">
                <?php if ($myStaff['photo_url']): ?>
                <img src="<?= h($myStaff['photo_url']) ?>" style="width:80px;height:80px;border-radius:50%;object-fit:cover;">
                <?php else: ?>
                <div style="width:80px;height:80px;border-radius:50%;background:#e8f5f0;display:flex;align-items:center;justify-content:center;font-size:2em;">👤</div>
                <?php endif; ?>
                <div>
                    <div style="font-size:1.1em;font-weight:bold;"><?= h($myStaff['name']) ?></div>
                    <div style="color:#888;font-size:0.9em;"><?= h($myStaff['role_name'] ?? '役割未設定') ?></div>
                </div>
            </div>
            <div class="form-group">
                <label>プロフィール写真を変更</label>
                <input type="file" name="photo" accept="image/*">
            </div>
            <div class="form-group">
                <label>新しいパスワード <span style="font-weight:normal;color:#888;font-size:0.85em;">（変更する場合のみ）</span></label>
                <input type="password" name="new_password" placeholder="8文字以上" autocomplete="new-password">
            </div>
            <button class="btn btn-primary" type="submit">更新する</button>
        </form>
    </div>
</div>
</div>

<?php
    include __DIR__ . '/_footer.php';
    exit;
}

// ============================================================
// 管理者用：全スタッフ管理
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $id       = (int)$_POST['id'];
        $photoUrl = $_POST['current_photo'] ?: null;
        if (!empty($_FILES['photo']['size'])) {
            $newUrl = handlePhotoUpload($_FILES['photo'], $id);
            if ($newUrl) $photoUrl = $newUrl;
        }
        $passwordHash = null;
        if (!empty($_POST['new_password'])) {
            if (strlen($_POST['new_password']) < 8) {
                $msg = 'パスワードは8文字以上にしてください'; $msgType = 'danger';
                goto render;
            }
            $passwordHash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
        }
        $nominationFee = (int)($_POST['nomination_fee'] ?? 0);
        // ログインID重複チェック（UNIQUE制約違反による500を防ぐ）
        $loginId = $_POST['login_id'] ?: null;
        if ($loginId !== null) {
            $dup = $db->prepare('SELECT name FROM staff WHERE login_id = ? AND id <> ?');
            $dup->execute([$loginId, $id]);
            if ($dupName = $dup->fetchColumn()) {
                $msg = "ログインID「{$loginId}」は既に {$dupName} さんが使用しています。別のIDを指定してください。";
                $msgType = 'danger';
                goto render;
            }
        }
        $sql = 'UPDATE staff SET name=?, role_id=?, photo_url=?, can_cut=?, can_color=?, can_perm=?, can_treatment=?, display_order=?, is_active=?, is_admin=?, login_id=?, nomination_fee=?';
        $params = [
            $_POST['name'], $_POST['role_id'] ?: null, $photoUrl,
            isset($_POST['can_cut']) ? 1 : 0, isset($_POST['can_color']) ? 1 : 0,
            isset($_POST['can_perm']) ? 1 : 0, isset($_POST['can_treatment']) ? 1 : 0,
            (int)$_POST['display_order'], isset($_POST['is_active']) ? 1 : 0,
            isset($_POST['is_admin']) ? 1 : 0, $loginId,
            $nominationFee,
        ];
        if ($passwordHash) { $sql .= ', password_hash=?'; $params[] = $passwordHash; }
        $sql .= ' WHERE id=?'; $params[] = $id;
        $db->prepare($sql)->execute($params);

        // 歩率設定 UPSERT
        $menuRate    = (float)($_POST['menu_rate'] ?? 0);
        $productRate = (float)($_POST['product_rate'] ?? 0);
        $incentiveEnabled = isset($_POST['incentive_enabled']) ? 1 : 0;
        $db->prepare('
            INSERT INTO staff_incentive_rates (staff_id, menu_rate, product_rate, incentive_enabled)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                menu_rate=VALUES(menu_rate),
                product_rate=VALUES(product_rate),
                incentive_enabled=VALUES(incentive_enabled)
        ')->execute([$id, $menuRate, $productRate, $incentiveEnabled]);

        auditLog('update', 'staff', $id, "スタッフ情報更新：{$_POST['name']}");
        $msg = '更新しました';
    }

    if ($action === 'add') {
        // ログインID重複チェック
        $loginId = $_POST['login_id'] ?: null;
        if ($loginId !== null) {
            $dup = $db->prepare('SELECT name FROM staff WHERE login_id = ?');
            $dup->execute([$loginId]);
            if ($dupName = $dup->fetchColumn()) {
                $msg = "ログインID「{$loginId}」は既に {$dupName} さんが使用しています。別のIDを指定してください。";
                $msgType = 'danger';
                goto render;
            }
        }
        $db->prepare('INSERT INTO staff (name, role_id, can_cut, can_color, can_perm, can_treatment, display_order, is_active, is_admin, login_id) VALUES (?,?,?,?,?,?,?,1,?,?)')
           ->execute([$_POST['name'], $_POST['role_id'] ?: null, isset($_POST['can_cut'])?1:0, isset($_POST['can_color'])?1:0, isset($_POST['can_perm'])?1:0, isset($_POST['can_treatment'])?1:0, (int)$_POST['display_order'], isset($_POST['is_admin'])?1:0, $loginId]);
        $newId = (int)$db->lastInsertId();
        if (!empty($_FILES['photo']['size'])) { $url = handlePhotoUpload($_FILES['photo'], $newId); if ($url) $db->prepare('UPDATE staff SET photo_url=? WHERE id=?')->execute([$url, $newId]); }
        if (!empty($_POST['new_password'])) { $db->prepare('UPDATE staff SET password_hash=? WHERE id=?')->execute([password_hash($_POST['new_password'], PASSWORD_BCRYPT), $newId]); }
        auditLog('create', 'staff', $newId, "スタッフ追加：{$_POST['name']}");
        $msg = 'スタッフを追加しました';
    }

    if ($action === 'add_role') {
        $db->prepare('INSERT INTO staff_roles (name, display_order) VALUES (?,?)')->execute([$_POST['role_name'], (int)$_POST['role_order']]);
        $msg = 'ロールを追加しました';
    }
}

render:
$staffList = $db->query('
    SELECT s.*, r.name AS role_name,
           COALESCE(ir.menu_rate, 0)    AS menu_rate,
           COALESCE(ir.product_rate, 0) AS product_rate,
           COALESCE(ir.incentive_enabled, 1) AS incentive_enabled
    FROM staff s
    LEFT JOIN staff_roles r ON s.role_id = r.id
    LEFT JOIN staff_incentive_rates ir ON ir.staff_id = s.id
    ORDER BY s.display_order
')->fetchAll();
$roles     = $db->query('SELECT * FROM staff_roles ORDER BY display_order')->fetchAll();
$pageTitle = 'スタッフ管理';
include __DIR__ . '/_header.php';
?>

<div class="page-title">スタッフ管理</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<!-- ロールマスタ -->
<div class="card">
    <div class="card-header">役割マスタ <button class="btn btn-sm btn-secondary" onclick="toggleSection('addRole')">＋ 追加</button></div>
    <div id="addRole" style="display:none;padding:16px;background:#f8f9fa;border-bottom:1px solid #eee;">
        <form method="post" style="display:flex;gap:12px;align-items:flex-end;">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add_role">
            <div class="form-group" style="margin:0;flex:1;"><label>役割名</label><input type="text" name="role_name" required placeholder="例：トップスタイリスト"></div>
            <div class="form-group" style="margin:0;width:80px;"><label>表示順</label><input type="number" name="role_order" value="<?= count($roles)+1 ?>"></div>
            <button class="btn btn-primary btn-sm" type="submit">追加</button>
        </form>
    </div>
    <div class="card-body" style="padding:12px 20px;">
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php foreach ($roles as $r): ?>
            <span style="background:#e8f5f0;color:#6B9E8A;padding:4px 14px;border-radius:20px;font-size:0.9em;font-weight:600;"><?= h($r['name']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- スタッフ一覧 -->
<?php foreach ($staffList as $s): ?>
<div class="card">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:12px;">
            <?php if ($s['photo_url']): ?>
            <img src="<?= h($s['photo_url']) ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
            <?php else: ?>
            <div style="width:40px;height:40px;border-radius:50%;background:#e8f5f0;display:flex;align-items:center;justify-content:center;font-size:1.2em;">👤</div>
            <?php endif; ?>
            <div>
                <div><?= h($s['name']) ?><?= $s['is_active'] ? '' : ' <span style="color:#999;font-size:0.8em;">（非表示）</span>' ?></div>
                <div style="font-size:0.8em;color:#888;"><?= h($s['role_name'] ?? '役割未設定') ?><?= $s['is_admin'] ? ' · <span style="color:#e67e22;">管理者</span>' : '' ?></div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
                    <?php if ($s['nomination_fee'] > 0): ?>
                    <span style="background:#fff3e0;color:#e67e22;padding:2px 8px;border-radius:10px;font-size:0.78em;font-weight:600;">指名料 ¥<?= number_format($s['nomination_fee']) ?></span>
                    <?php endif; ?>
                    <?php if ($s['incentive_enabled']): ?>
                    <span style="background:#e8f5f0;color:#6B9E8A;padding:2px 8px;border-radius:10px;font-size:0.78em;">施術<?= number_format((float)$s['menu_rate'], 1) ?>% / 物販<?= number_format((float)$s['product_rate'], 1) ?>%</span>
                    <?php else: ?>
                    <span style="background:#f5f5f5;color:#aaa;padding:2px 8px;border-radius:10px;font-size:0.78em;">インセンティブ無効</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <button class="btn btn-sm btn-secondary" onclick="toggleSection('staff<?= $s['id'] ?>')">編集</button>
    </div>
    <div id="staff<?= $s['id'] ?>" style="display:none;">
    <div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <input type="hidden" name="current_photo" value="<?= h($s['photo_url'] ?? '') ?>">
            <div style="display:grid;grid-template-columns:120px 1fr 1fr 1fr 1fr;gap:16px;align-items:start;">
                <div>
                    <label>写真</label>
                    <?php if ($s['photo_url']): ?>
                    <img src="<?= h($s['photo_url']) ?>" style="width:100px;height:100px;border-radius:8px;object-fit:cover;display:block;margin-bottom:8px;">
                    <?php else: ?>
                    <div style="width:100px;height:100px;border-radius:8px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:2em;margin-bottom:8px;">👤</div>
                    <?php endif; ?>
                    <input type="file" name="photo" accept="image/*" style="font-size:0.8em;">
                </div>
                <div>
                    <div class="form-group"><label>名前</label><input type="text" name="name" value="<?= h($s['name']) ?>" required></div>
                    <div class="form-group"><label>役割</label>
                        <select name="role_id"><option value="">未設定</option><?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>" <?= $s['role_id']==$r['id']?'selected':'' ?>><?= h($r['name']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="form-group"><label>表示順</label><input type="number" name="display_order" value="<?= h($s['display_order']) ?>" style="width:80px;"></div>
                </div>
                <div>
                    <div class="form-group"><label>ログインID</label><input type="text" name="login_id" value="<?= h($s['login_id'] ?? '') ?>" placeholder="ログインに使うID" autocomplete="off"></div>
                    <div class="form-group"><label>新しいパスワード</label><input type="password" name="new_password" placeholder="8文字以上（変更時のみ）" autocomplete="new-password"></div>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
                        <label style="font-weight:normal;display:flex;align-items:center;gap:5px;"><input type="checkbox" name="is_admin" <?= $s['is_admin']?'checked':'' ?>> 管理者権限</label>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:5px;"><input type="checkbox" name="is_active" <?= $s['is_active']?'checked':'' ?>> 有効</label>
                    </div>
                </div>
                <div>
                    <label>担当メニュー</label>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                        <label style="font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="can_cut" <?= $s['can_cut']?'checked':'' ?>> カット</label>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="can_color" <?= $s['can_color']?'checked':'' ?>> カラー</label>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="can_perm" <?= $s['can_perm']?'checked':'' ?>> パーマ</label>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="can_treatment" <?= $s['can_treatment']?'checked':'' ?>> トリートメント</label>
                    </div>
                </div>
                <div>
                    <label>報酬設定</label>
                    <div style="display:flex;flex-direction:column;gap:10px;margin-top:6px;">
                        <div class="form-group" style="margin:0;">
                            <label style="font-size:0.85em;color:#555;">指名料（円）</label>
                            <input type="number" name="nomination_fee" value="<?= (int)($s['nomination_fee'] ?? 0) ?>" min="0" step="100" style="width:100%;" placeholder="0">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label style="font-size:0.85em;color:#555;">施術歩率（%）</label>
                            <input type="number" name="menu_rate" value="<?= number_format((float)$s['menu_rate'], 1) ?>" min="0" max="100" step="0.5" style="width:100%;" placeholder="0.0">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label style="font-size:0.85em;color:#555;">物販歩率（%）</label>
                            <input type="number" name="product_rate" value="<?= number_format((float)$s['product_rate'], 1) ?>" min="0" max="100" step="0.5" style="width:100%;" placeholder="0.0">
                        </div>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:6px;font-size:0.9em;">
                            <input type="checkbox" name="incentive_enabled" <?= $s['incentive_enabled'] ? 'checked' : '' ?>>
                            インセンティブ有効
                        </label>
                    </div>
                </div>
            </div>
            <button class="btn btn-primary btn-sm" type="submit">更新</button>
        </form>
    </div>
    </div>
</div>
<?php endforeach; ?>

<!-- 新規追加 -->
<div class="card">
    <div class="card-header">＋ スタッフを追加 <button class="btn btn-sm btn-secondary" onclick="toggleSection('addStaff')">開く</button></div>
    <div id="addStaff" style="display:none;"><div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:120px 1fr 1fr 1fr;gap:16px;align-items:start;">
                <div>
                    <label>写真</label>
                    <div style="width:100px;height:100px;border-radius:8px;background:#f0f0f0;display:flex;align-items:center;justify-content:center;font-size:2em;margin-bottom:8px;">👤</div>
                    <input type="file" name="photo" accept="image/*" style="font-size:0.8em;">
                </div>
                <div>
                    <div class="form-group"><label>名前 *</label><input type="text" name="name" required></div>
                    <div class="form-group"><label>役割</label><select name="role_id"><option value="">未設定</option><?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>表示順</label><input type="number" name="display_order" value="<?= count($staffList)+1 ?>" style="width:80px;"></div>
                </div>
                <div>
                    <div class="form-group"><label>ログインID</label><input type="text" name="login_id" autocomplete="off"></div>
                    <div class="form-group"><label>パスワード</label><input type="password" name="new_password" placeholder="8文字以上" autocomplete="new-password"></div>
                    <label style="font-weight:normal;display:flex;align-items:center;gap:5px;"><input type="checkbox" name="is_admin"> 管理者権限</label>
                </div>
                <div>
                    <label>担当メニュー</label>
                    <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
                        <label style="font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="can_cut" checked> カット</label>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="can_color"> カラー</label>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="can_perm"> パーマ</label>
                        <label style="font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="can_treatment"> トリートメント</label>
                    </div>
                </div>
            </div>
            <button class="btn btn-primary btn-sm" type="submit">追加</button>
        </form>
    </div></div>
</div>

<script>
function toggleSection(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
