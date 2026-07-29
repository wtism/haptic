<?php
// admin/customer_modal.php  - お客様詳細モーダルコンテンツ（Ajax）
require_once __DIR__ . '/auth.php';
requireLogin();

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$msg = '';

if (!$id) { echo '❌ IDが不正です'; exit; }

// POST処理（モーダル内フォーム送信）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_customer') {
        $referrerId = $_POST['referrer_id'] ? (int)$_POST['referrer_id'] : null;

        // 紹介者名からIDを検索
        if ($_POST['referrer_name'] ?? '') {
            $s = $db->prepare('SELECT id FROM customers WHERE name = ? LIMIT 1');
            $s->execute([trim($_POST['referrer_name'])]);
            $ref = $s->fetch();
            $referrerId = $ref['id'] ?? null;
        }

        $db->prepare('
            UPDATE customers SET name=?, phone=?, address=?, birthdate=?, referrer_id=?, updated_at=NOW()
            WHERE id=?
        ')->execute([
            $_POST['name'],
            $_POST['phone']     ?: null,
            $_POST['address']   ?: null,
            $_POST['birthdate'] ?: null,
            $referrerId,
            $id,
        ]);
        $msg = '✅ 更新しました';
    }

    if ($action === 'issue_coupon') {
        $code     = strtoupper(substr(md5(uniqid()), 0, 8));
        $discount = (int)$_POST['discount'];
        $desc     = $_POST['description'] ?: '割引クーポン';
        $expired  = $_POST['expired_at'] ?: null;
        $db->prepare('
            INSERT INTO coupons (customer_id, code, description, discount, expired_at)
            VALUES (?,?,?,?,?)
        ')->execute([$id, $code, $desc, $discount, $expired]);
        $msg = "✅ クーポンを発行しました（コード：{$code}）";
    }
}

// お客様情報取得
$stmt = $db->prepare('SELECT c.*, r.name AS referrer_name FROM customers c LEFT JOIN customers r ON c.referrer_id = r.id WHERE c.id = ?');
$stmt->execute([$id]);
$c = $stmt->fetch();
if (!$c) { echo '❌ お客様が見つかりません'; exit; }

// 来店履歴（直近5件）
$stmt = $db->prepare('
    SELECT r.start_at, r.status, m.name AS menu_name, s.name AS staff_name
    FROM reservations r
    LEFT JOIN menus m ON r.menu_id = m.id
    LEFT JOIN staff s ON r.staff_id = s.id
    WHERE r.customer_id = ?
    ORDER BY r.start_at DESC LIMIT 5
');
$stmt->execute([$id]);
$history = $stmt->fetchAll();

// クーポン一覧
$stmt = $db->prepare('SELECT * FROM coupons WHERE customer_id = ? ORDER BY issued_at DESC');
$stmt->execute([$id]);
$coupons = $stmt->fetchAll();
?>

<?php if ($msg): ?>
<div class="alert alert-<?= str_starts_with($msg, '✅') ? 'success' : 'danger' ?>" style="margin-bottom:16px;"><?= h($msg) ?></div>
<?php endif; ?>

<!-- 基本情報編集 -->
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <input type="hidden" name="action" value="update_customer">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
        <div class="form-group" style="margin:0;">
            <label>お名前</label>
            <input type="text" name="name" value="<?= h($c['name'] ?? '') ?>" required>
        </div>
        <div class="form-group" style="margin:0;">
            <label>電話番号</label>
            <input type="tel" name="phone" value="<?= h($c['phone'] ?? '') ?>" placeholder="090-0000-0000">
        </div>
        <div class="form-group" style="margin:0;">
            <label>誕生日</label>
            <input type="date" name="birthdate" value="<?= h($c['birthdate'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label>紹介者名</label>
            <input type="text" name="referrer_name" value="<?= h($c['referrer_name'] ?? '') ?>" placeholder="お客様名で検索">
        </div>
    </div>
    <div class="form-group" style="margin-bottom:12px;">
        <label>住所
            <?php if ($c['address']): ?>
            <a href="https://maps.google.com/?q=<?= urlencode($c['address']) ?>" target="_blank" style="font-weight:normal;font-size:0.85em;">🗺 地図を開く</a>
            <?php endif; ?>
        </label>
        <input type="text" name="address" value="<?= h($c['address'] ?? '') ?>" placeholder="例：静岡県静岡市葵区...">
    </div>
    <button class="btn btn-primary btn-sm" type="submit">更新する</button>
</form>

<hr style="margin:20px 0;border:none;border-top:1px solid #eee;">

<!-- クーポン発行 -->
<div style="margin-bottom:16px;">
    <strong style="font-size:0.9em;">🎫 クーポン発行</strong>
    <form method="post" style="display:grid;grid-template-columns:1fr 1fr auto;gap:8px;margin-top:8px;align-items:end;">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="issue_coupon">
        <div class="form-group" style="margin:0;">
            <label>内容</label>
            <input type="text" name="description" value="次回割引クーポン" required>
        </div>
        <div class="form-group" style="margin:0;">
            <label>割引額（円）</label>
            <input type="number" name="discount" value="500" min="0" required>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">発行</button>
        <div class="form-group" style="margin:0;grid-column:span 3;">
            <label>有効期限</label>
            <input type="date" name="expired_at" value="<?= date('Y-m-d', strtotime('+3 months')) ?>">
        </div>
    </form>
</div>

<!-- クーポン一覧 -->
<?php if ($coupons): ?>
<div style="margin-bottom:16px;">
    <strong style="font-size:0.9em;">発行済みクーポン</strong>
    <table style="font-size:0.85em;margin-top:8px;">
        <tr><th>コード</th><th>内容</th><th>割引</th><th>有効期限</th><th>状態</th></tr>
        <?php foreach ($coupons as $cp): ?>
        <tr>
            <td><code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;font-size:1em;"><?= h($cp['code']) ?></code></td>
            <td><?= h($cp['description']) ?></td>
            <td>¥<?= number_format($cp['discount']) ?></td>
            <td><?= $cp['expired_at'] ? h(date('Y/m/d', strtotime($cp['expired_at']))) : '無期限' ?></td>
            <td>
                <?php if ($cp['used_at']): ?>
                <span class="badge" style="background:#e2e3e5;color:#383d41;">使用済 <?= h(date('m/d', strtotime($cp['used_at']))) ?></span>
                <?php elseif ($cp['expired_at'] && strtotime($cp['expired_at']) < time()): ?>
                <span class="badge" style="background:#f8d7da;color:#721c24;">期限切れ</span>
                <?php else: ?>
                <span class="badge" style="background:#d4edda;color:#155724;">未使用</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<!-- 来店履歴 -->
<div>
    <strong style="font-size:0.9em;">来店履歴（直近5件）</strong>
    <table style="font-size:0.85em;margin-top:8px;">
        <tr><th>日時</th><th>メニュー</th><th>担当</th><th>状態</th></tr>
        <?php foreach ($history as $h_): ?>
        <tr>
            <td><?= h(date('Y/m/d', strtotime($h_['start_at']))) ?></td>
            <td><?= h($h_['menu_name']) ?></td>
            <td><?= h($h_['staff_name'] ?? '-') ?></td>
            <td><span class="badge badge-<?= h($h_['status']) ?>"><?= ['pending'=>'仮予約','confirmed'=>'確定','completed'=>'完了','cancelled'=>'ｷｬﾝｾﾙ'][$h_['status']] ?? $h_['status'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($history)): ?>
        <tr><td colspan="4" style="color:#888;padding:10px;">来店履歴がありません</td></tr>
        <?php endif; ?>
    </table>
</div>
