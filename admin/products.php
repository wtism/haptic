<?php
// admin/products.php  - 商品・資材マスタ管理
require_once __DIR__ . '/auth.php';
requireLogin();

$db  = db();
$msg = '';
$msgType = 'success';

$categoryLabels = [
    'shampoo'   => 'シャンプー',
    'treatment' => 'トリートメント',
    'outbath'   => 'アウトバス',
    'styling'   => 'スタイリング',
    'color'     => 'カラー剤',
    'perm'      => 'パーマ剤',
    'sanitary'  => '衛生用品',
    'equipment' => '器具・備品',
    'other'     => 'その他',
];

$unitOptions = ['個','本','袋','箱','セット','ml','L','g','kg','枚','冊','ロール'];
$typeLabels  = ['product' => '🛍 販売商品', 'material' => '🔧 資材'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $db->prepare('
            INSERT INTO products (name, item_type, maker, category, price, cost_price, stock, stock_alert, alert_enabled, unit, sku, display_order, is_active, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)
        ')->execute([
            $_POST['name'], $_POST['item_type'] ?: 'product',
            $_POST['maker'] ?: null, $_POST['category'],
            (int)$_POST['price'],
            $_POST['cost_price'] !== '' ? (int)$_POST['cost_price'] : null,
            (int)$_POST['stock'], (int)$_POST['stock_alert'],
            isset($_POST['alert_enabled']) ? 1 : 0,
            $_POST['unit'] ?: '個', $_POST['sku'] ?: null,
            (int)$_POST['display_order'],
            $_POST['status'] ?: 'active',
        ]);
        header('Location: ' . adminUrl('products.php') . '?msg=added&tab=' . ($_POST['item_type'] ?: 'product')); exit;
    }

    if ($action === 'update') {
        $db->prepare('
            UPDATE products SET name=?, item_type=?, maker=?, category=?, price=?, cost_price=?,
                stock_alert=?, alert_enabled=?, unit=?, sku=?, display_order=?, is_active=?, status=?
            WHERE id=?
        ')->execute([
            $_POST['name'], $_POST['item_type'] ?: 'product',
            $_POST['maker'] ?: null, $_POST['category'],
            (int)$_POST['price'],
            $_POST['cost_price'] !== '' ? (int)$_POST['cost_price'] : null,
            (int)$_POST['stock_alert'],
            isset($_POST['alert_enabled']) ? 1 : 0,
            $_POST['unit'] ?: '個', $_POST['sku'] ?: null,
            (int)$_POST['display_order'],
            isset($_POST['is_active']) ? 1 : 0,
            $_POST['status'] ?: 'active',
            (int)$_POST['id'],
        ]);
        header('Location: ' . adminUrl('products.php') . '?msg=updated&tab=' . ($_POST['item_type'] ?: 'product')); exit;
    }



    // 在庫アラートのON/OFFをワンクリックで切り替え
    if ($action === 'toggle_alert') {
        $db->prepare('UPDATE products SET alert_enabled = 1 - alert_enabled WHERE id=?')->execute([(int)$_POST['id']]);
        $stmt = $db->prepare('SELECT alert_enabled FROM products WHERE id=?');
        $stmt->execute([(int)$_POST['id']]);
        $msgKey = $stmt->fetchColumn() ? 'alert_on' : 'alert_off';
        header('Location: ' . adminUrl('products.php') . '?msg=' . $msgKey . '&tab=' . ($_POST['tab'] ?: 'product')); exit;
    }

    if ($action === 'delete') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM product_sales WHERE product_id=?');
        $stmt->execute([(int)$_POST['id']]);
        if ($stmt->fetchColumn() > 0) {
            $db->prepare('UPDATE products SET is_active=0 WHERE id=?')->execute([(int)$_POST['id']]);
            header('Location: ' . adminUrl('products.php') . '?msg=deactivated&tab=' . ($_POST['tab'] ?: 'product')); exit;
        }
        $db->prepare('DELETE FROM products WHERE id=?')->execute([(int)$_POST['id']]);
        header('Location: ' . adminUrl('products.php') . '?msg=deleted&tab=' . ($_POST['tab'] ?: 'product')); exit;
    }
}

$msgMap = [
    'added'         => '追加しました',
    'updated'       => '更新しました',
    'deleted'       => '削除しました',
    'deactivated'   => '販売履歴があるため無効化しました',
    'alert_on'      => '在庫アラートをONにしました',
    'alert_off'     => '在庫アラートをOFFにしました',
];
if (isset($msgMap[$_GET['msg'] ?? ''])) {
    $msg = $msgMap[$_GET['msg']];
    if (in_array($_GET['msg'], ['deleted','deactivated'])) $msgType = 'danger';
}

$activeTab = $_GET['tab'] ?? 'product';
$products  = $db->query("SELECT * FROM products WHERE item_type='product' ORDER BY status, category, display_order")->fetchAll();
$materials = $db->query("SELECT * FROM products WHERE item_type='material' ORDER BY status, category, display_order")->fetchAll();



$pageTitle = '商品・資材管理';
include __DIR__ . '/_header.php';
?>

<div class="page-title">商品・資材管理</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<div style="font-size:0.88em;color:#888;margin-bottom:14px;">
    ※ 在庫数は<a href="<?= adminUrl('stock.php') ?>">在庫管理</a>から入荷登録で管理します
</div>

<!-- タブ -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #eee;">
    <a href="<?= adminUrl('products.php') ?>?tab=product" style="padding:10px 24px;font-weight:bold;text-decoration:none;border-bottom:<?= $activeTab==='product'?'3px solid #6B9E8A;color:#6B9E8A':'none;color:#888' ?>;">🛍 販売商品（<?= count($products) ?>）</a>
    <a href="<?= adminUrl('products.php') ?>?tab=material" style="padding:10px 24px;font-weight:bold;text-decoration:none;border-bottom:<?= $activeTab==='material'?'3px solid #6B9E8A;color:#6B9E8A':'none;color:#888' ?>;">🔧 資材（<?= count($materials) ?>）</a>
</div>

<?php
$currentList = $activeTab === 'product' ? $products : $materials;
$currentCat  = '';
$currentStatus = '';
foreach ($currentList as $p):
    // 販売中/終了 セクション区切り
    if ($p['status'] !== $currentStatus):
        $currentStatus = $p['status'];
        $currentCat = ''; // カテゴリリセット
        if ($currentStatus === 'discontinued'):
?>
<div style="margin:24px 0 8px;padding:8px 14px;background:#f8f9fa;border-radius:6px;color:#888;font-size:0.9em;font-weight:bold;">
    ── 販売終了 ──
</div>
<?php
        endif;
    endif;

    if ($p['category'] !== $currentCat):
        $currentCat = $p['category'];
?>
<div style="font-weight:bold;color:<?= $currentStatus==='discontinued'?'#aaa':'#6B9E8A' ?>;margin:12px 0 4px;font-size:0.88em;border-left:3px solid <?= $currentStatus==='discontinued'?'#ccc':'#6B9E8A' ?>;padding-left:8px;">
    <?= h($categoryLabels[$currentCat] ?? $currentCat) ?>
</div>
<?php endif; ?>

<div class="card" id="prod<?= $p['id'] ?>" style="<?= $currentStatus==='discontinued'?'opacity:0.7':'' ?>">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <div>
                <span style="font-weight:bold;"><?= h($p['name']) ?></span>
                <?php if ($p['maker']): ?><span style="color:#888;font-size:0.82em;margin-left:6px;"><?= h($p['maker']) ?></span><?php endif; ?>
                <?php if ($activeTab === 'product' && $p['status']==='active'): ?>
                <span style="font-weight:bold;color:#6B9E8A;margin-left:8px;">¥<?= number_format($p['price']) ?></span>
                <?php endif; ?>
                <?php if ($p['cost_price'] !== null): ?>
                <span style="color:#888;font-size:0.82em;margin-left:6px;">仕入:¥<?= number_format($p['cost_price']) ?></span>
                <?php endif; ?>
                <?php if ($p['status'] === 'discontinued'): ?>
                <span style="background:#e2e3e5;color:#383d41;padding:1px 8px;border-radius:10px;font-size:0.78em;margin-left:6px;">販売終了</span>
                <?php endif; ?>
            </div>
            <?php if ($p['status'] === 'active'): ?>
            <?php
            $alertOn    = !empty($p['alert_enabled']);
            $stockColor = !$alertOn
                ? '#e9ecef;color:#6c757d'
                : ($p['stock'] <= 0 ? '#f8d7da;color:#721c24' : ($p['stock'] <= $p['stock_alert'] ? '#fff3cd;color:#856404' : '#d4edda;color:#155724'));
            ?>
            <a href="<?= adminUrl('stock.php') ?>?product_id=<?= $p['id'] ?>" style="text-decoration:none;">
                <span style="background:<?= $stockColor ?>;padding:2px 10px;border-radius:12px;font-size:0.85em;font-weight:bold;">
                    在庫 <?= $p['stock'] ?><?= h($p['unit']) ?>
                </span>
            </a>
            <?php if (!$alertOn): ?>
            <span style="background:#e9ecef;color:#6c757d;padding:2px 8px;border-radius:12px;font-size:0.78em;margin-left:4px;">🔕 アラートOFF</span>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <div style="display:flex;gap:6px;">
            <?php if ($p['status'] === 'active'): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="toggle_alert">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
                <button class="btn btn-sm btn-secondary view-only" type="submit"
                        title="<?= empty($p['alert_enabled']) ? '在庫アラートを出すようにする' : '在庫アラートを出さないようにする' ?>">
                    <?= empty($p['alert_enabled']) ? '🔕 アラートOFF' : '🔔 アラートON' ?>
                </button>
            </form>
            <?php endif; ?>
            <button class="btn btn-sm btn-secondary view-only" onclick="setMode('prod<?= $p['id'] ?>','edit')">✏️ 編集</button>
            <button class="btn btn-sm btn-secondary edit-only" onclick="setMode('prod<?= $p['id'] ?>','view')">キャンセル</button>
            <form method="post" style="display:inline;" onsubmit="return confirm('削除しますか？')">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
                <button class="btn btn-danger btn-sm view-only" type="submit">削除</button>
            </form>
        </div>
    </div>
    <div class="card-body edit-only">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <input type="hidden" name="item_type" value="<?= h($p['item_type']) ?>">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr <?= $activeTab==='product'?'90px ':'' ?>90px 80px 80px 80px 80px 100px auto;gap:10px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>名前</label><input type="text" name="name" value="<?= h($p['name']) ?>" required></div>
                <div class="form-group" style="margin:0;"><label>メーカー</label><input type="text" name="maker" value="<?= h($p['maker'] ?? '') ?>"></div>
                <div class="form-group" style="margin:0;"><label>カテゴリ</label>
                    <select name="category"><?php foreach ($categoryLabels as $k=>$v): ?><option value="<?= $k ?>" <?= $p['category']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <?php if ($activeTab === 'product'): ?>
                <div class="form-group" style="margin:0;"><label>税込価格</label><input type="number" name="price" value="<?= h($p['price']) ?>" min="0"></div>
                <?php else: ?><input type="hidden" name="price" value="0"><?php endif; ?>
                <div class="form-group" style="margin:0;"><label>仕入値</label><input type="number" name="cost_price" value="<?= h($p['cost_price'] ?? '') ?>" placeholder="-"></div>
                <div class="form-group" style="margin:0;"><label>アラート数<span style="font-size:0.78em;color:#888;"> (在庫がこれ以下で警告)</span></label><input type="number" name="stock_alert" value="<?= h($p['stock_alert']) ?>" min="0"></div>
                <div class="form-group" style="margin:0;"><label>単位</label>
                    <select name="unit"><?php foreach ($unitOptions as $u): ?><option value="<?= $u ?>" <?= $p['unit']===$u?'selected':'' ?>><?= $u ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group" style="margin:0;"><label>ステータス</label>
                    <select name="status">
                        <option value="active"       <?= $p['status']==='active'      ?'selected':'' ?>>販売中</option>
                        <option value="discontinued" <?= $p['status']==='discontinued'?'selected':'' ?>>販売終了</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight:normal;display:flex;align-items:center;gap:4px;margin-bottom:2px;"><input type="checkbox" name="is_active" <?= $p['is_active']?'checked':'' ?>> 有効</label>
                    <label style="font-weight:normal;display:flex;align-items:center;gap:4px;margin-bottom:6px;" title="OFFにすると在庫が少なくても警告を出しません"><input type="checkbox" name="alert_enabled" <?= !empty($p['alert_enabled'])?'checked':'' ?>> 在庫アラート</label>
                    <button class="btn btn-primary btn-sm" type="submit">保存</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($currentList)): ?>
<div class="card"><div class="card-body" style="text-align:center;color:#888;padding:30px;">まだ登録がありません</div></div>
<?php endif; ?>

<!-- 新規追加 -->
<div class="card" style="margin-top:20px;">
    <div class="card-header">＋ <?= $typeLabels[$activeTab] ?? '商品' ?>を追加 <button class="btn btn-sm btn-secondary" onclick="toggleSection('addItem')">開く</button></div>
    <div id="addItem" style="display:none;"><div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="item_type" value="<?= h($activeTab) ?>">
            <input type="hidden" name="sku" value="">
            <input type="hidden" name="display_order" value="<?= count($currentList)+1 ?>">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr <?= $activeTab==='product'?'90px ':'' ?>90px 80px 80px 80px 80px auto;gap:10px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>名前 *</label><input type="text" name="name" required></div>
                <div class="form-group" style="margin:0;"><label>メーカー</label><input type="text" name="maker"></div>
                <div class="form-group" style="margin:0;"><label>カテゴリ</label>
                    <select name="category"><?php foreach ($categoryLabels as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <?php if ($activeTab === 'product'): ?>
                <div class="form-group" style="margin:0;"><label>税込価格</label><input type="number" name="price" value="0" min="0"></div>
                <?php else: ?><input type="hidden" name="price" value="0"><?php endif; ?>
                <div class="form-group" style="margin:0;"><label>仕入値</label><input type="number" name="cost_price" placeholder="-"></div>
                <div class="form-group" style="margin:0;"><label>アラート数</label><input type="number" name="stock_alert" value="5" min="0"></div>
                <div class="form-group" style="margin:0;"><label>アラート</label>
                    <label style="font-weight:normal;display:flex;align-items:center;gap:4px;height:38px;" title="OFFにすると在庫が少なくても警告を出しません"><input type="checkbox" name="alert_enabled" checked> 出す</label>
                </div>
                <div class="form-group" style="margin:0;"><label>単位</label>
                    <select name="unit"><?php foreach ($unitOptions as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?></select>
                </div>
                <input type="hidden" name="status" value="active">
                <button class="btn btn-primary" type="submit" style="margin-bottom:1px;">追加</button>
            </div>
        </form>
    </div></div>
</div>

<script>
function toggleSection(id) { const el=document.getElementById(id); el.style.display=el.style.display==='none'?'block':'none'; }
function setMode(cardId, mode) {
    const card = document.getElementById(cardId);
    card.classList.remove('view-mode','edit-mode');
    card.classList.add(mode+'-mode');
    card.querySelectorAll('.edit-only').forEach(el=>el.style.display=mode==='edit'?'':'none');
    card.querySelectorAll('.view-only').forEach(el=>el.style.display=mode==='view'?'':'none');
}
document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.card').forEach(c=>{
        c.classList.add('view-mode');
        c.querySelectorAll('.edit-only').forEach(el=>el.style.display='none');
    });
});
</script>
<?php include __DIR__ . '/_footer.php'; ?>
