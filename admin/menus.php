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
        header('Location: ' . adminUrl('menus.php') . '?msg=updated&filter=' . ($_POST['filter'] ?: 'active')); exit;
    }

    if ($action === 'add') {
        $db->prepare('INSERT INTO menus (name, category, duration_min, price, display_order, is_active) VALUES (?,?,?,?,?,1)')
           ->execute([$_POST['name'], $_POST['category'], (int)$_POST['duration_min'], (int)$_POST['price'], (int)$_POST['display_order']]);
        header('Location: ' . adminUrl('menus.php') . '?msg=added&filter=' . ($_POST['filter'] ?: 'active')); exit;
    }
}

$msgMap = ['updated'=>'更新しました','added'=>'追加しました'];
if (isset($msgMap[$_GET['msg'] ?? ''])) $msg = $msgMap[$_GET['msg']];

$allMenus = $db->query('SELECT * FROM menus ORDER BY display_order')->fetchAll();

// 表示フィルタ（既定は有効なメニューのみ）
$filter = $_GET['filter'] ?? 'active';
if (!in_array($filter, ['active','inactive','all'], true)) $filter = 'active';

$activeCount   = count(array_filter($allMenus, fn($m) => $m['is_active']));
$inactiveCount = count($allMenus) - $activeCount;

$menus = array_values(array_filter($allMenus, function ($m) use ($filter) {
    if ($filter === 'active')   return (bool)$m['is_active'];
    if ($filter === 'inactive') return !$m['is_active'];
    return true;
}));

// カテゴリはDBの実データ（日本語）と揃える。既存データにある値も取りこぼさないよう合成する
$categories = ['カット'=>'カット','カラー'=>'カラー','パーマ'=>'パーマ','ストレート'=>'ストレート','トリートメント'=>'トリートメント','その他'=>'その他'];
foreach ($db->query('SELECT DISTINCT category FROM menus') as $c) {
    if ($c['category'] !== '' && !isset($categories[$c['category']])) $categories[$c['category']] = $c['category'];
}
$pageTitle = 'メニュー管理';
include __DIR__ . '/_header.php';
?>

<div class="page-title">メニュー管理</div>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

<!-- 表示切替 -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #eee;">
    <?php foreach ([
        'active'   => ['🟢 有効',   $activeCount],
        'inactive' => ['⚪ 無効',   $inactiveCount],
        'all'      => ['すべて',    count($allMenus)],
    ] as $key => [$label, $cnt]): ?>
    <a href="<?= adminUrl('menus.php') ?>?filter=<?= $key ?>"
       style="padding:10px 24px;font-weight:bold;text-decoration:none;border-bottom:<?= $filter===$key?'3px solid #6B9E8A;color:#6B9E8A':'none;color:#888' ?>;">
        <?= $label ?>（<?= $cnt ?>）
    </a>
    <?php endforeach; ?>
</div>

<?php foreach ($menus as $m): ?>
<div class="card" id="menu<?= $m['id'] ?>">
    <div class="card-header" style="align-items:center;">
        <div style="display:flex;align-items:center;gap:12px;min-width:0;">
            <span style="font-size:1.1em;line-height:1;" title="<?= $m['is_active'] ? '有効（予約画面に表示）' : '無効（予約画面に表示されません）' ?>">
                <?= $m['is_active'] ? '🟢' : '⚪' ?>
            </span>
            <div style="min-width:0;">
                <div style="font-weight:bold;font-size:1.08em;color:<?= $m['is_active'] ? '#222' : '#999' ?>;">
                    <?= h($m['name']) ?><?= $m['is_active'] ? '' : '<span style="font-weight:normal;font-size:0.8em;margin-left:6px;">（無効）</span>' ?>
                </div>
                <div style="color:#222;font-size:0.92em;margin-top:3px;">
                    <?= h($categories[$m['category']] ?? $m['category']) ?>
                    ／ <?= $m['duration_min'] ?>分
                    ／ ¥<?= number_format($m['price']) ?>
                </div>
            </div>
        </div>
        <div style="flex-shrink:0;">
            <button class="btn btn-sm btn-secondary view-only" onclick="setMode('menu<?= $m['id'] ?>','edit')">✏️ 編集</button>
            <button class="btn btn-sm btn-secondary edit-only" onclick="setMode('menu<?= $m['id'] ?>','view')">キャンセル</button>
        </div>
    </div>
    <div class="card-body edit-only">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $m['id'] ?>">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
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

<?php if (empty($menus)): ?>
<div class="card"><div class="card-body" style="text-align:center;color:#888;padding:30px;">
    このタブに表示するメニューはありません
</div></div>
<?php endif; ?>

<div class="card" style="margin-top:20px;">
    <div class="card-header">＋ メニューを追加 <button class="btn btn-sm btn-secondary" onclick="toggleSection('addMenu')">開く</button></div>
    <div id="addMenu" style="display:none;"><div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="filter" value="<?= h($filter) ?>">
            <div style="display:grid;grid-template-columns:1fr 120px 100px 100px 60px auto;gap:12px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>メニュー名 *</label><input type="text" name="name" required></div>
                <div class="form-group" style="margin:0;"><label>カテゴリ</label>
                    <select name="category"><?php foreach ($categories as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group" style="margin:0;"><label>時間（分）</label><input type="number" name="duration_min" value="60"></div>
                <div class="form-group" style="margin:0;"><label>料金（円）</label><input type="number" name="price" value="0"></div>
                <div class="form-group" style="margin:0;"><label>順</label><input type="number" name="display_order" value="<?= count($allMenus)+1 ?>"></div>
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
