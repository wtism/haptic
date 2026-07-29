<?php
// admin/coupon_templates.php  - クーポンマスタ管理
require_once __DIR__ . '/auth.php';
requireLogin();

$db      = db();
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $discountType = $_POST['discount_type'] === 'percent' ? 'percent' : 'amount';
        $discount     = $discountType === 'amount' ? (int)$_POST['discount'] : 0;
        $discountRate = $discountType === 'percent' ? max(1, min(100, (int)$_POST['discount_rate'])) : null;
        $db->prepare('
            INSERT INTO coupon_templates (name, description, discount, discount_rate, discount_type, valid_days, coupon_type, display_order, is_active)
            VALUES (?,?,?,?,?,?,?,?,1)
        ')->execute([
            $_POST['name'], $_POST['description'],
            $discount, $discountRate, $discountType,
            (int)$_POST['valid_days'],
            $_POST['coupon_type'] ?: 'manual', (int)$_POST['display_order'],
        ]);
        header('Location: ' . adminUrl('coupon_templates.php') . '?msg=added'); exit;
    }

    if ($action === 'update') {
        $discountType = $_POST['discount_type'] === 'percent' ? 'percent' : 'amount';
        $discount     = $discountType === 'amount' ? (int)$_POST['discount'] : 0;
        $discountRate = $discountType === 'percent' ? max(1, min(100, (int)$_POST['discount_rate'])) : null;
        $db->prepare('
            UPDATE coupon_templates SET name=?, description=?, discount=?, discount_rate=?, discount_type=?, valid_days=?, coupon_type=?, display_order=?, is_active=?
            WHERE id=?
        ')->execute([
            $_POST['name'], $_POST['description'],
            $discount, $discountRate, $discountType,
            (int)$_POST['valid_days'],
            $_POST['coupon_type'] ?: 'manual', (int)$_POST['display_order'],
            isset($_POST['is_active']) ? 1 : 0,
            (int)$_POST['id'],
        ]);
        header('Location: ' . adminUrl('coupon_templates.php') . '?msg=updated'); exit;
    }

    if ($action === 'delete') {
        $db->prepare('DELETE FROM coupon_templates WHERE id=?')->execute([(int)$_POST['id']]);
        header('Location: ' . adminUrl('coupon_templates.php') . '?msg=deleted'); exit;
    }
}

$msgMap = ['added'=>'テンプレートを追加しました','updated'=>'更新しました','deleted'=>'削除しました'];
if (isset($msgMap[$_GET['msg'] ?? ''])) {
    $msg = $msgMap[$_GET['msg']];
    if ($_GET['msg'] === 'deleted') $msgType = 'danger';
}

$typeLabels = ['manual'=>'手動発行','birthday'=>'誕生日','visit'=>'来店'];
$templates  = $db->query('SELECT * FROM coupon_templates ORDER BY display_order')->fetchAll();
$pageTitle  = 'クーポンマスタ';
include __DIR__ . '/_header.php';

// 割引表示ヘルパー
function discountLabel(array $t): string {
    if (($t['discount_type'] ?? 'amount') === 'percent') {
        return ($t['discount_rate'] ?? 0) . '% OFF';
    }
    return '¥' . number_format($t['discount']);
}
?>

<div class="page-title">クーポンマスタ</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<?php foreach ($templates as $t): ?>
<div class="card" id="tmpl<?= $t['id'] ?>">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:12px;">
            <div>
                <span style="font-weight:bold;"><?= h($t['name']) ?></span>
                <span style="font-size:0.82em;color:#888;margin-left:8px;">
                    <?= discountLabel($t) ?> · <?= $t['valid_days'] ?>日間 · <?= h($typeLabels[$t['coupon_type']] ?? $t['coupon_type']) ?>
                </span>
                <?= $t['is_active'] ? '' : '<span style="color:#999;font-size:0.8em;margin-left:6px;">（無効）</span>' ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-sm btn-secondary view-only" onclick="setMode('tmpl<?= $t['id'] ?>','edit')">✏️ 編集</button>
            <button class="btn btn-sm btn-secondary edit-only" onclick="setMode('tmpl<?= $t['id'] ?>','view')">キャンセル</button>
            <form method="post" style="display:inline;" onsubmit="return confirm('削除しますか？')">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button class="btn btn-danger btn-sm view-only" type="submit">削除</button>
            </form>
        </div>
    </div>
    <div class="card-body edit-only">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr auto auto 130px 60px auto;gap:12px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>テンプレート名</label><input type="text" name="name" value="<?= h($t['name']) ?>" required></div>
                <div class="form-group" style="margin:0;"><label>クーポン内容</label><input type="text" name="description" value="<?= h($t['description']) ?>" required></div>
                <!-- 割引種別 + 金額/率 -->
                <div class="form-group" style="margin:0;">
                    <label>割引種別</label>
                    <select name="discount_type" onchange="toggleDiscountInput(this,'edit<?= $t['id'] ?>')" style="padding:6px;">
                        <option value="amount" <?= ($t['discount_type']??'amount')==='amount'?'selected':'' ?>>金額（円）</option>
                        <option value="percent" <?= ($t['discount_type']??'')==='percent'?'selected':'' ?>>% OFF</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;" id="edit<?= $t['id'] ?>_amount" <?= ($t['discount_type']??'amount')==='percent'?'style="display:none;"':'' ?>>
                    <label>割引額（円）</label>
                    <input type="number" name="discount" value="<?= h($t['discount']) ?>" min="0" style="width:90px;">
                </div>
                <div class="form-group" style="margin:0;" id="edit<?= $t['id'] ?>_percent" <?= ($t['discount_type']??'amount')!=='percent'?'style="display:none;"':'' ?>>
                    <label>割引率（%）</label>
                    <input type="number" name="discount_rate" value="<?= h($t['discount_rate'] ?? '') ?>" min="1" max="100" style="width:70px;">
                </div>
                <div class="form-group" style="margin:0;"><label>有効日数</label><input type="number" name="valid_days" value="<?= h($t['valid_days']) ?>" min="1"></div>
                <div class="form-group" style="margin:0;"><label>種別</label>
                    <select name="coupon_type">
                        <option value="manual"   <?= $t['coupon_type']==='manual'  ?'selected':'' ?>>手動発行</option>
                        <option value="birthday" <?= $t['coupon_type']==='birthday'?'selected':'' ?>>誕生日</option>
                        <option value="visit"    <?= $t['coupon_type']==='visit'   ?'selected':'' ?>>来店</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;"><label>順</label><input type="number" name="display_order" value="<?= h($t['display_order']) ?>" style="width:50px;"></div>
                <div style="display:flex;flex-direction:column;gap:6px;">
                    <label style="font-weight:normal;display:flex;align-items:center;gap:5px;white-space:nowrap;"><input type="checkbox" name="is_active" <?= $t['is_active']?'checked':'' ?>> 有効</label>
                    <button class="btn btn-primary btn-sm" type="submit">保存</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- 新規追加 -->
<div class="card">
    <div class="card-header">
        ＋ テンプレートを追加
        <button class="btn btn-sm btn-secondary" onclick="toggleSection('addTemplate')">開く</button>
    </div>
    <div id="addTemplate" style="display:none;">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:1fr 1fr auto auto 130px 60px auto;gap:12px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>テンプレート名 *</label><input type="text" name="name" required placeholder="例：次回割引500円"></div>
                <div class="form-group" style="margin:0;"><label>クーポン内容 *</label><input type="text" name="description" required placeholder="例：次回割引クーポン"></div>
                <div class="form-group" style="margin:0;">
                    <label>割引種別</label>
                    <select name="discount_type" onchange="toggleDiscountInput(this,'newTmpl')" style="padding:6px;">
                        <option value="amount">金額（円）</option>
                        <option value="percent">% OFF</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;" id="newTmpl_amount">
                    <label>割引額（円）</label>
                    <input type="number" name="discount" value="500" min="0" style="width:90px;">
                </div>
                <div class="form-group" style="margin:0;display:none;" id="newTmpl_percent">
                    <label>割引率（%）</label>
                    <input type="number" name="discount_rate" value="10" min="1" max="100" style="width:70px;">
                </div>
                <div class="form-group" style="margin:0;"><label>有効日数</label><input type="number" name="valid_days" value="30" min="1"></div>
                <div class="form-group" style="margin:0;"><label>種別</label>
                    <select name="coupon_type">
                        <option value="manual">手動発行</option>
                        <option value="birthday">誕生日</option>
                        <option value="visit">来店</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;"><label>順</label><input type="number" name="display_order" value="<?= count($templates)+1 ?>" style="width:50px;"></div>
                <button class="btn btn-primary" type="submit" style="margin-bottom:1px;">追加</button>
            </div>
        </form>
    </div>
    </div>
</div>

<script>
function toggleSection(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function setMode(cardId, mode) {
    const card = document.getElementById(cardId);
    card.classList.remove('view-mode','edit-mode');
    card.classList.add(mode + '-mode');
    card.querySelectorAll('.edit-only').forEach(el => el.style.display = mode === 'edit' ? '' : 'none');
    card.querySelectorAll('.view-only').forEach(el => el.style.display = mode === 'view' ? '' : 'none');
}
function toggleDiscountInput(sel, prefix) {
    const isPercent = sel.value === 'percent';
    const amountEl  = document.getElementById(prefix + '_amount');
    const percentEl = document.getElementById(prefix + '_percent');
    if (amountEl)  amountEl.style.display  = isPercent ? 'none' : '';
    if (percentEl) percentEl.style.display = isPercent ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.card').forEach(c => {
        c.classList.add('view-mode');
        c.querySelectorAll('.edit-only').forEach(el => el.style.display = 'none');
    });
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
