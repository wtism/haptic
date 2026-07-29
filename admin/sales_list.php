<?php
// admin/sales_list.php - 施術・物販一覧（月ごと）
require_once __DIR__ . '/auth.php';
requireLogin();

$db    = db();
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));
$tab   = $_GET['tab'] ?? 'menu'; // menu or product

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));
$prevY = $month == 1 ? $year-1 : $year; $prevM = $month == 1 ? 12 : $month-1;
$nextY = $month == 12 ? $year+1 : $year; $nextM = $month == 12 ? 1 : $month+1;

// 施術一覧
$menuList = $db->prepare("
    SELECT r.id, r.start_at, r.status,
           c.id AS customer_id, c.name AS customer_name,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.menu')), m.name) AS menu_name, COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0) AS price,
           s.name AS staff_name
    FROM reservations r
    JOIN customers c ON r.customer_id = c.id
    JOIN menus m     ON r.menu_id = m.id
    LEFT JOIN staff s ON r.staff_id = s.id
    WHERE r.status = 'completed' AND DATE(r.start_at) BETWEEN ? AND ?
    ORDER BY r.start_at
");
$menuList->execute([$startDate, $endDate]);
$menuList = $menuList->fetchAll();
$menuTotal   = array_sum(array_column($menuList, 'price'));
$menuCount   = count($menuList);

// 物販一覧
$productList = $db->prepare("
    SELECT ps.id, ps.sold_at, ps.quantity, ps.price,
           p.name AS product_name, p.maker,
           c.id AS customer_id, c.name AS customer_name,
           s.name AS staff_name,
           r.id AS reservation_id
    FROM product_sales ps
    JOIN products p  ON ps.product_id = p.id
    JOIN customers c ON ps.customer_id = c.id
    LEFT JOIN reservations r ON ps.reservation_id = r.id
    LEFT JOIN staff s ON ps.staff_id = s.id
    WHERE ps.sold_at BETWEEN ? AND ?
    ORDER BY ps.sold_at, ps.id
");
$productList->execute([$startDate, $endDate]);
$productList = $productList->fetchAll();
$productTotal = array_sum(array_map(fn($r) => $r['price'] * $r['quantity'], $productList));
$productQty   = array_sum(array_column($productList, 'quantity'));

$pageTitle = '施術・物販一覧';
include __DIR__ . '/_header.php';
?>

<div class="page-title">施術・物販一覧</div>

<!-- 月選択 -->
<div class="card">
    <div class="card-body" style="padding:12px 20px;">
        <form method="get" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="tab" value="<?= h($tab) ?>">
            <select name="year" style="width:100px;">
                <?php for ($y = date('Y'); $y >= date('Y')-3; $y--): ?>
                <option value="<?= $y ?>" <?= $year==$y?'selected':'' ?>><?= $y ?>年</option>
                <?php endfor; ?>
            </select>
            <select name="month" style="width:80px;">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $month==$m?'selected':'' ?>><?= $m ?>月</option>
                <?php endfor; ?>
            </select>
            <button class="btn btn-secondary btn-sm" type="submit">表示</button>
            <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>&tab=<?= h($tab) ?>" class="btn btn-sm btn-secondary">◀ 前月</a>
            <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>&tab=<?= h($tab) ?>" class="btn btn-sm btn-secondary">翌月 ▶</a>
            <span style="margin-left:8px;color:#888;font-size:0.9em;"><?= $year ?>年<?= $month ?>月</span>
        </form>
    </div>
</div>

<!-- サマリー -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px;">
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.4em;font-weight:bold;color:#3498db;">¥<?= number_format($menuTotal) ?></div>
        <div style="font-size:0.85em;color:#555;margin-top:4px;font-weight:600;">施術売上</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.4em;font-weight:bold;color:#3498db;"><?= $menuCount ?>件</div>
        <div style="font-size:0.85em;color:#555;margin-top:4px;font-weight:600;">施術件数</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.4em;font-weight:bold;color:#e67e22;">¥<?= number_format($productTotal) ?></div>
        <div style="font-size:0.85em;color:#555;margin-top:4px;font-weight:600;">物販売上</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.4em;font-weight:bold;color:#e67e22;"><?= $productQty ?>点</div>
        <div style="font-size:0.85em;color:#555;margin-top:4px;font-weight:600;">物販点数</div>
    </div>
</div>

<!-- タブ -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #6B9E8A;">
    <a href="?year=<?= $year ?>&month=<?= $month ?>&tab=menu"
       style="padding:8px 24px;background:<?= $tab==='menu'?'#6B9E8A':'#e8f5f0' ?>;color:<?= $tab==='menu'?'#fff':'#6B9E8A' ?>;border-radius:6px 6px 0 0;font-weight:bold;font-size:0.95em;text-decoration:none;">
        ✂️ 施術一覧（<?= $menuCount ?>件）
    </a>
    <a href="?year=<?= $year ?>&month=<?= $month ?>&tab=product"
       style="padding:8px 24px;background:<?= $tab==='product'?'#6B9E8A':'#e8f5f0' ?>;color:<?= $tab==='product'?'#fff':'#6B9E8A' ?>;border-radius:6px 6px 0 0;font-weight:bold;font-size:0.95em;text-decoration:none;margin-left:4px;">
        🛍️ 物販一覧（<?= $productQty ?>点）
    </a>
</div>

<?php if ($tab === 'menu'): ?>
<!-- 施術一覧 -->
<div class="card">
    <div class="card-header">✂️ 施術一覧　<?= $year ?>年<?= $month ?>月（<?= $menuCount ?>件　計 ¥<?= number_format($menuTotal) ?>）</div>
    <div class="card-body" style="padding:0;">
    <?php if (empty($menuList)): ?>
        <p style="padding:20px;color:#888;text-align:center;">施術データがありません</p>
    <?php else: ?>
        <table>
            <tr><th>日時</th><th>お客様</th><th>メニュー</th><th style="text-align:right;">金額</th><th>担当</th><th></th></tr>
            <?php
            $prevDay = '';
            foreach ($menuList as $r):
                $day    = date('m/d', strtotime($r['start_at']));
                $dow    = (int)date('w', strtotime($r['start_at']));
                $rowBg  = $dow === 0 ? 'background:#fff0f3;' : ($dow === 6 ? 'background:#f0f4ff;' : '');
                $dowColor = $dow === 0 ? 'color:#e74c3c;' : ($dow === 6 ? 'color:#3498db;' : '');
                $dayLabel = $day !== $prevDay ? $day . '（' . ['日','月','火','水','木','金','土'][$dow] . '）' : '';
                $prevDay = $day;
            ?>
            <tr style="border-bottom:1px solid #eee;<?= $rowBg ?>">
                <td style="white-space:nowrap;<?= $dowColor ?>"><?= $dayLabel ?> <?= date('H:i', strtotime($r['start_at'])) ?></td>
                <td><a href="<?= adminUrl('customers.php') ?>?id=<?= $r['customer_id'] ?>" style="color:#555;text-decoration:none;"><?= h($r['customer_name']) ?>様</a></td>
                <td><?= h($r['menu_name']) ?></td>
                <td style="text-align:right;font-weight:bold;">¥<?= number_format($r['price']) ?></td>
                <td style="color:#6B9E8A;font-size:0.9em;"><?= h($r['staff_name'] ?? '未定') ?></td>
                <td><a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">詳細</a></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#f0faf4;font-weight:bold;border-top:2px solid #6B9E8A;">
                <td colspan="3" style="padding:9px 12px;text-align:right;color:#888;">合計</td>
                <td style="padding:9px 12px;text-align:right;color:#6B9E8A;">¥<?= number_format($menuTotal) ?></td>
                <td colspan="2"></td>
            </tr>
        </table>
    <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- 物販一覧 -->
<div class="card">
    <div class="card-header">🛍️ 物販一覧　<?= $year ?>年<?= $month ?>月（<?= $productQty ?>点　計 ¥<?= number_format($productTotal) ?>）</div>
    <div class="card-body" style="padding:0;">
    <?php if (empty($productList)): ?>
        <p style="padding:20px;color:#888;text-align:center;">物販データがありません</p>
    <?php else: ?>
        <table>
            <tr><th>日付</th><th>お客様</th><th>商品</th><th style="text-align:right;">数量</th><th style="text-align:right;">金額</th><th>担当</th><th></th></tr>
            <?php
            $prevDay = '';
            foreach ($productList as $p):
                $day  = date('m/d', strtotime($p['sold_at']));
                $dow  = (int)date('w', strtotime($p['sold_at']));
                $rowBg = $dow === 0 ? 'background:#fff0f3;' : ($dow === 6 ? 'background:#f0f4ff;' : '');
                $dowColor = $dow === 0 ? 'color:#e74c3c;' : ($dow === 6 ? 'color:#3498db;' : '');
                $dayLabel = $day !== $prevDay ? $day . '（' . ['日','月','火','水','木','金','土'][$dow] . '）' : '';
                $prevDay = $day;
            ?>
            <tr style="border-bottom:1px solid #eee;<?= $rowBg ?>">
                <td style="white-space:nowrap;<?= $dowColor ?>"><?= $dayLabel ?></td>
                <td><a href="<?= adminUrl('customers.php') ?>?id=<?= $p['customer_id'] ?>" style="color:#555;text-decoration:none;"><?= h($p['customer_name']) ?>様</a></td>
                <td><?= h($p['product_name']) ?><?php if ($p['maker']): ?> <span style="color:#aaa;font-size:0.82em;">/<?= h($p['maker']) ?></span><?php endif; ?></td>
                <td style="text-align:right;"><?= $p['quantity'] ?>個</td>
                <td style="text-align:right;font-weight:bold;">¥<?= number_format($p['price'] * $p['quantity']) ?></td>
                <td style="color:#6B9E8A;font-size:0.9em;"><?= h($p['staff_name'] ?? '未定') ?></td>
                <td>
                    <?php if ($p['reservation_id']): ?>
                    <a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $p['reservation_id'] ?>" class="btn btn-sm btn-secondary">詳細</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#fff8f0;font-weight:bold;border-top:2px solid #e67e22;">
                <td colspan="4" style="padding:9px 12px;text-align:right;color:#888;">合計</td>
                <td style="padding:9px 12px;text-align:right;color:#e67e22;">¥<?= number_format($productTotal) ?></td>
                <td colspan="2"></td>
            </tr>
        </table>
    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
