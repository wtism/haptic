<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$db  = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $db->prepare('UPDATE menus SET name=?, category=?, duration_min=?, price=?, display_order=?, is_active=? WHERE id=?')
           ->execute([$_POST['name'], $_POST['category'], (int)$_POST['duration_min'], (int)$_POST['price'], (int)$_POST['display_order'], isset($_POST['is_active'])?1:0, (int)$_POST['id']]);
        header('Location: ' . adminUrl('menus.php') . '?msg=updated'); exit;
    }

    if ($action === 'add') {
        $db->prepare('INSERT INTO menus (name, category, duration_min, price, display_order, is_active) VALUES (?,?,?,?,?,1)')
           ->execute([$_POST['name'], $_POST['category'], (int)$_POST['duration_min'], (int)$_POST['price'], (int)$_POST['display_order']]);
        header('Location: ' . adminUrl('menus.php') . '?msg=added'); exit;
    }
}

$msgMap = ['updated'=>'更新しました','added'=>'追加しました'];
if (isset($msgMap[$_GET['msg'] ?? ''])) $msg = $msgMap[$_GET['msg']];

$menus = $db->query('SELECT * FROM menus ORDER BY display_order')->fetchAll();
$categories = ['cut'=>'カット','color'=>'カラー','perm'=>'パーマ','treatment'=>'トリートメント','other'=>'その他'];
$pageTitle = 'メニュー管理';
include __DIR__ . '/_header.php';
?>

<div class="page-title">メニュー管理</div>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

<?php foreach ($menus as $m): ?>
<div class="card" id="menu<?= $m['id'] ?>">
    <div class="card-header">
        <div>
            <span style="font-weight:bold;"><?= h($m['name']) ?></span>
            <span style="font-size:0.82em;color:#888;margin-left:8px;">
                <?= h($categories[$m['category']] ?? $m['category']) ?> · <?= $m['duration_min'] ?>分 · ¥<?= number_format($m['price']) ?>
            </span>
            <?= $m['is_active'] ? '' : '<span style="color:#999;font-size:0.8em;margin-left:6px;">（無効）</span>' ?>
        </div>
        <div>
            <button class="btn btn-sm btn-secondary view-only" onclick="setMode('menu<?= $m['id'] ?>','edit')">✏️ 編集</button>
            <button class="btn btn-sm btn-secondary edit-only" onclick="setMode('menu<?= $m['id'] ?>','view')">キャンセル</button>
        </div>
    </div>
    <div class="card-body edit-only">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 120px 100px 100px 60px auto;gap:12px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>メニュー名</label><input type="text" name="name" value="<?= h($m['name']) ?>" required></div>
                <div class="form-group" style="margin:0;"><label>カテゴリ</label>
                    <select name="category"><?php foreach ($categories as $k=>$v): ?><option value="<?= $k ?>" <?= $m['category']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group" style="margin:0;"><label>時間（分）</label><input type="number" name="duration_min" value="<?= h($m['duration_min']) ?>"></div>
                <div class="form-group" style="margin:0;"><label>料金（円）</label><input type="number" name="price" value="<?= h($m['price']) ?>"></div>
                <div class="form-group" style="margin:0;"><label>順</label><input type="number" name="display_order" value="<?= h($m['display_order']) ?>"></div>
                <div>
                    <label style="font-weight:normal;display:flex;align-items:center;gap:5px;margin-bottom:8px;"><input type="checkbox" name="is_active" <?= $m['is_active']?'checked':'' ?>> 有効</label>
                    <button class="btn btn-primary btn-sm" type="submit">保存</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<div class="card">
    <div class="card-header">＋ メニューを追加 <button class="btn btn-sm btn-secondary" onclick="toggleSection('addMenu')">開く</button></div>
    <div id="addMenu" style="display:none;"><div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:1fr 120px 100px 100px 60px auto;gap:12px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>メニュー名 *</label><input type="text" name="name" required></div>
                <div class="form-group" style="margin:0;"><label>カテゴリ</label>
                    <select name="category"><?php foreach ($categories as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group" style="margin:0;"><label>時間（分）</label><input type="number" name="duration_min" value="60"></div>
                <div class="form-group" style="margin:0;"><label>料金（円）</label><input type="number" name="price" value="0"></div>
                <div class="form-group" style="margin:0;"><label>順</label><input type="number" name="display_order" value="<?= count($menus)+1 ?>"></div>
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
