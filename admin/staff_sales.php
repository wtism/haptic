<?php
// admin/staff_sales.php - スタッフ別施術・物販一覧
require_once __DIR__ . '/auth.php';
requireLogin();

$db    = db();
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));
$prevY = $month == 1 ? $year-1 : $year; $prevM = $month == 1 ? 12 : $month-1;
$nextY = $month == 12 ? $year+1 : $year; $nextM = $month == 12 ? 1 : $month+1;

// アクティブスタッフ一覧
$staffList = $db->query("SELECT id, name FROM staff WHERE is_active=1 ORDER BY display_order")->fetchAll();

// スタッフ別施術データ
$menuByStaff = [];
$menuStmt = $db->prepare("
    SELECT r.id, r.start_at,
           c.id AS customer_id, c.name AS customer_name,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.menu')), m.name) AS menu_name, COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0) AS price,
           s.id AS staff_id
    FROM reservations r
    JOIN customers c ON r.customer_id = c.id
    JOIN menus m     ON r.menu_id = m.id
    JOIN staff s     ON r.staff_id = s.id
    WHERE r.status = 'completed'
      AND DATE(r.start_at) BETWEEN ? AND ?
    ORDER BY r.start_at
");
$menuStmt->execute([$startDate, $endDate]);
foreach ($menuStmt->fetchAll() as $row) {
    $menuByStaff[$row['staff_id']][] = $row;
}

// スタッフ別物販データ
$productByStaff = [];
$productStmt = $db->prepare("
    SELECT ps.id, ps.sold_at, ps.quantity, ps.price,
           p.name AS product_name, p.maker,
           c.id AS customer_id, c.name AS customer_name,
           ps.staff_id,
           r.id AS reservation_id
    FROM product_sales ps
    JOIN products p  ON ps.product_id = p.id
    JOIN customers c ON ps.customer_id = c.id
    LEFT JOIN reservations r ON ps.reservation_id = r.id
    WHERE ps.sold_at BETWEEN ? AND ?
      AND ps.staff_id IS NOT NULL
    ORDER BY ps.sold_at, ps.id
");
$productStmt->execute([$startDate, $endDate]);
foreach ($productStmt->fetchAll() as $row) {
    $productByStaff[$row['staff_id']][] = $row;
}

// スタッフ別インセンティブ歩率
$rateStmt = $db->query("SELECT staff_id, menu_rate, product_rate, incentive_enabled FROM staff_incentive_rates");
$rateMap  = [];
foreach ($rateStmt->fetchAll() as $r) {
    $rateMap[$r['staff_id']] = $r;
}

// ============================================================
// 年次データ集計（4月始まり12ヶ月）
// ============================================================
$fiscalStartYear = isset($_GET['fiscal']) ? (int)$_GET['fiscal'] : (($month >= 4) ? $year : $year - 1);
$prevFiscal = $fiscalStartYear - 1;
$nextFiscal = $fiscalStartYear + 1;

// スタッフ別・月別の年次集計
$annualByStaff = []; // [staff_id][fi] = ['menu'=>, 'product'=>, 'incentive'=>, 'visits'=>]
foreach ($staffList as $s) {
    $sid = $s['id'];
    $rate = $rateMap[$sid] ?? null;
    for ($fi = 0; $fi < 12; $fi++) {
        $fm = (($fi + 3) % 12) + 1;
        $fy = $fiscalStartYear + (int)(($fi + 3) / 12);
        $sd = sprintf('%04d-%02d-01', $fy, $fm);
        $ed = date('Y-m-t', strtotime($sd));

        $ms = $db->prepare("SELECT COALESCE(SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0)),0) AS menu, COUNT(DISTINCT r.id) AS visits FROM reservations r LEFT JOIN menus m ON r.menu_id=m.id WHERE r.staff_id=? AND r.status='completed' AND DATE(r.start_at) BETWEEN ? AND ?");
        $ms->execute([$sid, $sd, $ed]); $md = $ms->fetch();
        $ps = $db->prepare("SELECT COALESCE(SUM(ps.price*ps.quantity),0) AS product FROM product_sales ps WHERE ps.staff_id=? AND ps.sold_at BETWEEN ? AND ?");
        $ps->execute([$sid, $sd, $ed]); $pd = $ps->fetch();

        $rm = (int)$md['menu']; $rp = (int)$pd['product'];
        $inc = ($rate && $rate['incentive_enabled']) ? (int)($rm * $rate['menu_rate'] / 100 + $rp * $rate['product_rate'] / 100) : 0;
        $annualByStaff[$sid][$fi] = ['year'=>$fy, 'month'=>$fm, 'menu'=>$rm, 'product'=>$rp, 'incentive'=>$inc, 'visits'=>(int)$md['visits'], 'total'=>$rm+$rp];
    }
}

$pageTitle = 'スタッフ別売上';
include __DIR__ . '/_header.php';
?>

<div class="page-title">スタッフ別売上</div>

<!-- 月選択 -->
<div class="card">
    <div class="card-body" style="padding:12px 20px;">
        <form method="get" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
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
            <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-sm btn-secondary">◀ 前月</a>
            <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-sm btn-secondary">翌月 ▶</a>
            <span style="margin-left:8px;color:#888;font-size:0.9em;"><?= $year ?>年<?= $month ?>月</span>
        </form>
    </div>
</div>

<!-- スタッフ別サマリー -->
<div style="display:grid;grid-template-columns:repeat(<?= min(count($staffList), 4) ?>,1fr);gap:12px;margin-bottom:20px;">
<?php foreach ($staffList as $s):
    $sid      = $s['id'];
    $mRows    = $menuByStaff[$sid]    ?? [];
    $pRows    = $productByStaff[$sid] ?? [];
    $mTotal   = array_sum(array_column($mRows, 'price'));
    $pTotal   = array_sum(array_map(fn($r) => $r['price'] * $r['quantity'], $pRows));
    $rate     = $rateMap[$sid] ?? null;
    $incentive = 0;
    if ($rate && $rate['incentive_enabled']) {
        $incentive = (int)($mTotal * $rate['menu_rate'] / 100 + $pTotal * $rate['product_rate'] / 100);
    }
?>
<div class="stat-card" style="text-align:center;padding:14px 12px;">
    <div style="font-weight:bold;font-size:1em;color:#444;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid #eee;"><?= h($s['name']) ?></div>
    <div style="font-size:1.4em;font-weight:bold;color:#6B9E8A;margin-bottom:8px;">¥<?= number_format($mTotal + $pTotal) ?></div>
    <div style="display:flex;justify-content:space-between;font-size:0.8em;margin-bottom:3px;">
        <span style="color:#888;">施術</span>
        <span style="color:#3498db;font-weight:bold;">¥<?= number_format($mTotal) ?></span>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:0.8em;margin-bottom:3px;">
        <span style="color:#888;">物販</span>
        <span style="color:#e67e22;font-weight:bold;">¥<?= number_format($pTotal) ?></span>
    </div>
    <?php if ($incentive > 0): ?>
    <div style="display:flex;justify-content:space-between;font-size:0.8em;margin-top:6px;padding-top:6px;border-top:1px solid #eee;">
        <span style="color:#888;">歩合</span>
        <span style="color:#8e44ad;font-weight:bold;">¥<?= number_format($incentive) ?></span>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<!-- ① 共通スタッフタブ -->
<div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:0;border-bottom:2px solid #6B9E8A;">
<?php foreach ($staffList as $i => $s): ?>
    <button id="stab-<?= $s['id'] ?>" onclick="switchStaff(<?= $s['id'] ?>)"
        style="padding:7px 18px;background:<?= $i===0?'#6B9E8A':'#e8f5f0' ?>;color:<?= $i===0?'#fff':'#6B9E8A' ?>;border:none;border-radius:6px 6px 0 0;font-weight:bold;cursor:pointer;font-size:0.9em;">
        <?= h($s['name']) ?>
    </button>
<?php endforeach; ?>
</div>

<!-- ② スタッフ別コンテナ（月次/年次タブをここに内包） -->
<?php foreach ($staffList as $i => $s):
    $sid = $s['id'];
?>
<div id="scontainer-<?= $sid ?>" style="display:<?= $i===0?'block':'none' ?>;">

<!-- 月次/年次タブ -->
<div style="display:flex;gap:0;margin:12px 0 0;border-bottom:2px solid #6B9E8A;">
    <button id="vtab-monthly-<?= $sid ?>" onclick="switchView('monthly',<?= $sid ?>)"
        style="padding:7px 20px;background:#6B9E8A;color:#fff;border:none;border-radius:6px 6px 0 0;font-weight:bold;cursor:pointer;font-size:0.88em;">📊 月次</button>
    <button id="vtab-yearly-<?= $sid ?>"  onclick="switchView('yearly',<?= $sid ?>)"
        style="padding:7px 20px;background:#e8f5f0;color:#6B9E8A;border:none;border-radius:6px 6px 0 0;font-weight:bold;cursor:pointer;font-size:0.88em;margin-left:4px;">📈 年次</button>
</div>

<!-- ===== 月次パネル ===== -->
<div id="vpanel-monthly-<?= $sid ?>">
<?php
    $mRows  = $menuByStaff[$sid]    ?? [];
    $pRows  = $productByStaff[$sid] ?? [];
    $mTotal = array_sum(array_column($mRows, 'price'));
    $pTotal = array_sum(array_map(fn($r) => $r['price'] * $r['quantity'], $pRows));
    $pQty   = array_sum(array_column($pRows, 'quantity'));
    $rate   = $rateMap[$sid] ?? null;
    $mIncentive = ($rate && $rate['incentive_enabled']) ? (int)($mTotal * $rate['menu_rate'] / 100) : 0;
    $pIncentive = ($rate && $rate['incentive_enabled']) ? (int)($pTotal * $rate['product_rate'] / 100) : 0;
?>
<!-- 月次サマリー -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:16px 0 20px;">
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.2em;font-weight:bold;color:#6B9E8A;">¥<?= number_format($mTotal + $pTotal) ?></div>
        <div style="font-size:0.82em;color:#555;margin-top:4px;font-weight:600;">総売上</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.2em;font-weight:bold;color:#3498db;">¥<?= number_format($mTotal) ?></div>
        <div style="font-size:0.82em;color:#888;margin-top:2px;"><?= count($mRows) ?>件</div>
        <div style="font-size:0.82em;color:#555;margin-top:3px;font-weight:600;">施術売上</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.2em;font-weight:bold;color:#e67e22;">¥<?= number_format($pTotal) ?></div>
        <div style="font-size:0.82em;color:#888;margin-top:2px;"><?= $pQty ?>点</div>
        <div style="font-size:0.82em;color:#555;margin-top:3px;font-weight:600;">物販売上</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <?php if ($rate): ?>
        <div style="font-size:1.0em;font-weight:bold;color:#8e44ad;">¥<?= number_format($mIncentive + $pIncentive) ?></div>
        <div style="font-size:0.78em;color:#aaa;margin-top:2px;">施術<?= $rate['menu_rate'] ?>% / 物販<?= $rate['product_rate'] ?>%</div>
        <?php else: ?><div style="font-size:0.85em;color:#aaa;">未設定</div><?php endif; ?>
        <div style="font-size:0.82em;color:#555;margin-top:3px;font-weight:600;">歩合計</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.2em;font-weight:bold;color:#555;"><?= count($mRows) > 0 ? '¥'.number_format((int)(($mTotal+$pTotal)/count($mRows))) : '—' ?></div>
        <div style="font-size:0.82em;color:#555;margin-top:4px;font-weight:600;">客単価</div>
    </div>
</div>

<!-- 施術リスト -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-header">✂️ 施術（<?= count($mRows) ?>件　¥<?= number_format($mTotal) ?>）
        <?php if ($mIncentive > 0): ?><span style="font-size:0.82em;color:#8e44ad;font-weight:normal;margin-left:8px;">歩合 ¥<?= number_format($mIncentive) ?></span><?php endif; ?>
    </div>
    <div class="card-body" style="padding:0;">
    <?php if (empty($mRows)): ?>
        <p style="padding:16px;color:#888;text-align:center;">施術データがありません</p>
    <?php else: ?>
        <table>
            <tr><th>日時</th><th>お客様</th><th>メニュー</th><th style="text-align:right;">売上金額</th><th style="text-align:right;">歩合金額</th><th></th></tr>
            <?php $prevDay2=''; foreach ($mRows as $r):
                $day = date('m/d', strtotime($r['start_at']));
                $dow = (int)date('w', strtotime($r['start_at']));
                $rowBg = $dow===0?'background:#fff0f3;':($dow===6?'background:#f0f4ff;':'');
                $dowColor = $dow===0?'color:#e74c3c;':($dow===6?'color:#3498db;':'');
                $dayLabel = $day!==$prevDay2 ? $day.'（'.['日','月','火','水','木','金','土'][$dow].'）' : '';
                $prevDay2 = $day;
                $rowIncentive = ($rate && $rate['incentive_enabled']) ? (int)($r['price'] * $rate['menu_rate'] / 100) : null;
            ?>
            <tr style="border-bottom:1px solid #eee;<?= $rowBg ?>">
                <td style="white-space:nowrap;<?= $dowColor ?>"><?= $dayLabel ?> <?= date('H:i', strtotime($r['start_at'])) ?></td>
                <td><a href="<?= adminUrl('customers.php') ?>?id=<?= $r['customer_id'] ?>" style="color:#555;text-decoration:none;"><?= h($r['customer_name']) ?>様</a></td>
                <td><?= h($r['menu_name']) ?></td>
                <td style="text-align:right;font-weight:bold;">¥<?= number_format($r['price']) ?></td>
                <td style="text-align:right;color:#8e44ad;"><?= $rowIncentive !== null ? '¥'.number_format($rowIncentive) : '<span style="color:#ddd;">—</span>' ?></td>
                <td><a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">詳細</a></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#f0faf4;font-weight:bold;border-top:2px solid #6B9E8A;">
                <td colspan="3" style="padding:8px 12px;text-align:right;color:#888;">合計</td>
                <td style="padding:8px 12px;text-align:right;color:#6B9E8A;">¥<?= number_format($mTotal) ?></td>
                <td style="padding:8px 12px;text-align:right;color:#8e44ad;"><?= $mIncentive > 0 ? '¥'.number_format($mIncentive) : '' ?></td>
                <td></td>
            </tr>
        </table>
    <?php endif; ?>
    </div>
</div>

<!-- 物販リスト -->
<div class="card">
    <div class="card-header">🛍️ 物販（<?= $pQty ?>点　¥<?= number_format($pTotal) ?>）
        <?php if ($pIncentive > 0): ?><span style="font-size:0.82em;color:#8e44ad;font-weight:normal;margin-left:8px;">歩合 ¥<?= number_format($pIncentive) ?></span><?php endif; ?>
    </div>
    <div class="card-body" style="padding:0;">
    <?php if (empty($pRows)): ?>
        <p style="padding:16px;color:#888;text-align:center;">物販データがありません</p>
    <?php else: ?>
        <table>
            <tr><th>日付</th><th>お客様</th><th>商品</th><th style="text-align:right;">数量</th><th style="text-align:right;">売上金額</th><th style="text-align:right;">歩合金額</th><th></th></tr>
            <?php $prevDay3=''; foreach ($pRows as $p):
                $day = date('m/d', strtotime($p['sold_at']));
                $dow = (int)date('w', strtotime($p['sold_at']));
                $rowBg = $dow===0?'background:#fff0f3;':($dow===6?'background:#f0f4ff;':'');
                $dowColor = $dow===0?'color:#e74c3c;':($dow===6?'color:#3498db;':'');
                $dayLabel = $day!==$prevDay3 ? $day.'（'.['日','月','火','水','木','金','土'][$dow].'）' : '';
                $prevDay3 = $day;
                $pRowTotal = $p['price'] * $p['quantity'];
                $pRowIncentive = ($rate && $rate['incentive_enabled']) ? (int)($pRowTotal * $rate['product_rate'] / 100) : null;
            ?>
            <tr style="border-bottom:1px solid #eee;<?= $rowBg ?>">
                <td style="white-space:nowrap;<?= $dowColor ?>"><?= $dayLabel ?></td>
                <td><a href="<?= adminUrl('customers.php') ?>?id=<?= $p['customer_id'] ?>" style="color:#555;text-decoration:none;"><?= h($p['customer_name']) ?>様</a></td>
                <td><?= h($p['product_name']) ?><?php if ($p['maker']): ?> <span style="color:#aaa;font-size:0.82em;">/<?= h($p['maker']) ?></span><?php endif; ?></td>
                <td style="text-align:right;"><?= $p['quantity'] ?>個</td>
                <td style="text-align:right;font-weight:bold;">¥<?= number_format($pRowTotal) ?></td>
                <td style="text-align:right;color:#8e44ad;"><?= $pRowIncentive !== null ? '¥'.number_format($pRowIncentive) : '<span style="color:#ddd;">—</span>' ?></td>
                <td><?php if ($p['reservation_id']): ?><a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $p['reservation_id'] ?>" class="btn btn-sm btn-secondary">詳細</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#fff8f0;font-weight:bold;border-top:2px solid #e67e22;">
                <td colspan="4" style="padding:8px 12px;text-align:right;color:#888;">合計</td>
                <td style="padding:8px 12px;text-align:right;color:#e67e22;">¥<?= number_format($pTotal) ?></td>
                <td style="padding:8px 12px;text-align:right;color:#8e44ad;"><?= $pIncentive > 0 ? '¥'.number_format($pIncentive) : '' ?></td>
                <td></td>
            </tr>
        </table>
    <?php endif; ?>
    </div>
</div>
</div><!-- /vpanel-monthly -->

<!-- ===== 年次パネル ===== -->
<div id="vpanel-yearly-<?= $sid ?>" style="display:none;">
<?php
    $rate    = $rateMap[$sid] ?? null;
    $rows    = $annualByStaff[$sid] ?? [];
    $totMenu = array_sum(array_column($rows, 'menu'));
    $totProd = array_sum(array_column($rows, 'product'));
    $totInc  = array_sum(array_column($rows, 'incentive'));
    $totVis  = array_sum(array_column($rows, 'visits'));
    $totAll  = $totMenu + $totProd;
?>

<!-- 前期/翌期ナビ -->
<div style="display:flex;gap:8px;align-items:center;margin:14px 0 16px;">
    <a href="?year=<?= $year ?>&month=<?= $month ?>&fiscal=<?= $prevFiscal ?>" class="btn btn-sm btn-secondary">＜前期</a>
    <span style="font-weight:bold;color:#555;"><?= $fiscalStartYear ?>年4月〜<?= $fiscalStartYear+1 ?>年3月</span>
    <a href="?year=<?= $year ?>&month=<?= $month ?>&fiscal=<?= $nextFiscal ?>" class="btn btn-sm btn-secondary">翌期＞</a>
</div>

<!-- 年次サマリーカード -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:20px;">
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.2em;font-weight:bold;color:#6B9E8A;">¥<?= number_format($totAll) ?></div>
        <div style="font-size:0.82em;color:#555;margin-top:4px;font-weight:600;">年間総売上</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.2em;font-weight:bold;color:#3498db;">¥<?= number_format($totMenu) ?></div>
        <div style="font-size:0.82em;color:#555;margin-top:4px;font-weight:600;">施術売上</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.2em;font-weight:bold;color:#e67e22;">¥<?= number_format($totProd) ?></div>
        <div style="font-size:0.82em;color:#555;margin-top:4px;font-weight:600;">物販売上</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.2em;font-weight:bold;color:#8e44ad;">¥<?= number_format($totInc) ?></div>
        <?php if ($rate && $rate['incentive_enabled']): ?>
        <div style="font-size:0.75em;color:#aaa;margin-top:2px;">施術<?= $rate['menu_rate'] ?>% / 物販<?= $rate['product_rate'] ?>%</div>
        <?php endif; ?>
        <div style="font-size:0.82em;color:#555;margin-top:3px;font-weight:600;">歩合計</div>
    </div>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.2em;font-weight:bold;color:#555;"><?= $totVis > 0 ? number_format($totVis).'名' : '—' ?></div>
        <div style="font-size:0.82em;color:#555;margin-top:4px;font-weight:600;">来店数</div>
    </div>
</div>

<!-- 年次グラフ -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-header"><?= $fiscalStartYear ?>年4月〜<?= $fiscalStartYear+1 ?>年3月　月次推移</div>
    <div class="card-body"><canvas id="staffChart-<?= $sid ?>" height="90"></canvas></div>
</div>

<!-- 月別集計テーブル -->
<div class="card">
    <div class="card-header">月別集計</div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:0.85em;">
        <thead>
            <tr style="background:#f4f6f8;">
                <th style="padding:6px 10px;text-align:left;border-bottom:2px solid #ddd;">月</th>
                <th style="padding:6px 10px;text-align:right;border-bottom:2px solid #ddd;">総売上</th>
                <th style="padding:6px 10px;text-align:right;border-bottom:2px solid #ddd;">施術</th>
                <th style="padding:6px 10px;text-align:right;border-bottom:2px solid #ddd;">物販</th>
                <th style="padding:6px 10px;text-align:right;border-bottom:2px solid #ddd;">歩合</th>
                <th style="padding:6px 10px;text-align:right;border-bottom:2px solid #ddd;">来店</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $fi => $row):
            $isThisMonth = ($row['year'] === $year && $row['month'] === $month);
            $isFuture    = ($row['year'] > date('Y') || ($row['year'] == date('Y') && $row['month'] > date('n')));
            $rowBg = $isThisMonth ? '#fffff0' : ($isFuture ? '#fafafa' : '#fff');
            $tc    = $isFuture ? '#ccc' : '#333';
        ?>
        <tr style="border-bottom:1px solid #eee;background:<?= $rowBg ?>;">
            <td style="padding:5px 10px;font-weight:<?= $isThisMonth?'bold':'normal' ?>;color:<?= $tc ?>;">
                <?= $row['month'] ?>月<?php if ($isThisMonth): ?> <span style="background:#6B9E8A;color:#fff;font-size:0.7em;padding:1px 5px;border-radius:8px;margin-left:4px;">今月</span><?php endif; ?>
            </td>
            <td style="padding:5px 10px;text-align:right;font-weight:bold;color:<?= $isFuture?'#ccc':'#6B9E8A' ?>;"><?= $row['total']>0?'¥'.number_format($row['total']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 10px;text-align:right;color:<?= $isFuture?'#ccc':'#3498db' ?>;"><?= $row['menu']>0?'¥'.number_format($row['menu']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 10px;text-align:right;color:<?= $isFuture?'#ccc':'#e67e22' ?>;"><?= $row['product']>0?'¥'.number_format($row['product']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 10px;text-align:right;color:<?= $isFuture?'#ccc':'#8e44ad' ?>;"><?= $row['incentive']>0?'¥'.number_format($row['incentive']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 10px;text-align:right;color:<?= $isFuture?'#ccc':'#555' ?>;"><?= $row['visits']>0?$row['visits'].'名':'<span style="color:#ddd;">—</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr style="background:#f0faf4;font-weight:bold;border-top:2px solid #6B9E8A;">
            <td style="padding:7px 10px;">合計</td>
            <td style="padding:7px 10px;text-align:right;color:#6B9E8A;">¥<?= number_format($totAll) ?></td>
            <td style="padding:7px 10px;text-align:right;color:#3498db;">¥<?= number_format($totMenu) ?></td>
            <td style="padding:7px 10px;text-align:right;color:#e67e22;">¥<?= number_format($totProd) ?></td>
            <td style="padding:7px 10px;text-align:right;color:#8e44ad;">¥<?= number_format($totInc) ?></td>
            <td style="padding:7px 10px;text-align:right;"><?= $totVis ?>名</td>
        </tr>
        </tfoot>
    </table>
    </div>
</div>
</div><!-- /vpanel-yearly -->

</div><!-- /scontainer -->
<?php endforeach; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
const staffIds = [<?= implode(',', array_column($staffList, 'id')) ?>];
const annualStaffData = <?= json_encode(array_map(fn($s) => [
    'id'   => $s['id'],
    'rows' => array_values($annualByStaff[$s['id']] ?? [])
], $staffList)) ?>;

// スタッフ切り替え（共通：コンテナごと）
function switchStaff(sid) {
    staffIds.forEach(id => {
        document.getElementById('scontainer-' + id).style.display = id === sid ? 'block' : 'none';
        const btn = document.getElementById('stab-' + id);
        btn.style.background = id === sid ? '#6B9E8A' : '#e8f5f0';
        btn.style.color      = id === sid ? '#fff'    : '#6B9E8A';
    });
}

// 月次/年次切り替え（スタッフごとに独立）
function switchView(view, sid) {
    ['monthly','yearly'].forEach(v => {
        const panel = document.getElementById('vpanel-' + v + '-' + sid);
        const btn   = document.getElementById('vtab-' + v + '-' + sid);
        if (!panel || !btn) return;
        panel.style.display  = v === view ? '' : 'none';
        btn.style.background = v === view ? '#6B9E8A' : '#e8f5f0';
        btn.style.color      = v === view ? '#fff'    : '#6B9E8A';
    });
    if (view === 'yearly') initStaffChart(sid);
}

// グラフ初期化（スタッフごと・一度だけ）
const chartInited = {};
function initStaffChart(sid) {
    if (chartInited[sid]) return;
    chartInited[sid] = true;
    const staff = annualStaffData.find(s => s.id === sid);
    if (!staff) return;
    const ctx = document.getElementById('staffChart-' + sid);
    if (!ctx) return;
    new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: staff.rows.map(d => d.month + '月'),
            datasets: [
                { type:'bar',  label:'施術売上', data: staff.rows.map(d=>d.menu),      backgroundColor:'rgba(107,158,138,0.85)', stack:'rev' },
                { type:'bar',  label:'物販売上', data: staff.rows.map(d=>d.product),   backgroundColor:'rgba(230,126,34,0.85)',  stack:'rev' },
                { type:'line', label:'歩合',     data: staff.rows.map(d=>d.incentive),
                  borderColor:'rgba(142,68,173,0.8)', backgroundColor:'rgba(142,68,173,0.1)',
                  borderWidth:2, pointRadius:3, tension:0.3, yAxisID:'y2', stack:undefined, order:0 }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode:'index', intersect:false },
            scales: {
                y:  { beginAtZero:true, position:'left',  ticks:{ callback: v=>'¥'+v.toLocaleString() } },
                y2: { beginAtZero:true, position:'right', grid:{ drawOnChartArea:false }, ticks:{ callback: v=>'¥'+v.toLocaleString() } }
            },
            plugins: {
                tooltip: { callbacks:{ label: c=>c.dataset.label+': ¥'+(c.raw||0).toLocaleString() } },
                legend: { position:'bottom' }
            }
        }
    });
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
