<?php
// admin/monthly.php  - 月次集計・売上管理
require_once __DIR__ . '/auth.php';
requireLogin();

$db  = db();
$msg = '';
$msgType = 'success';

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

// 経費追加・削除
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_expense') {
        $paidAt = $_POST['paid_at'] ?: date('Y-m-d');
        $y = (int)date('Y', strtotime($paidAt));
        $m = (int)date('m', strtotime($paidAt));
        $db->prepare('INSERT INTO expenses (year, month, category, description, amount, paid_at, note, created_by) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$y, $m, $_POST['category'], $_POST['description'], (int)$_POST['amount'], $paidAt, $_POST['note'] ?: null, currentAdminId()]);
        header('Location: ' . adminUrl('monthly.php') . "?year={$y}&month={$m}&msg=expense_added"); exit;
    }

    if ($action === 'delete_expense') {
        $db->prepare('DELETE FROM expenses WHERE id=?')->execute([(int)$_POST['id']]);
        header('Location: ' . adminUrl('monthly.php') . "?year={$year}&month={$month}&msg=expense_deleted"); exit;
    }
}

if (($_GET['msg'] ?? '') === 'expense_added')   $msg = '経費を追加しました';
if (($_GET['msg'] ?? '') === 'expense_deleted')  { $msg = '経費を削除しました'; $msgType = 'danger'; }

// ============================================================
// 月次データ集計
// ============================================================
$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));

// 施術売上（完了した予約のメニュー価格）
$revMenu = $db->prepare("
    SELECT COALESCE(SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0)), 0) AS total, COUNT(DISTINCT r.id) AS visits, COUNT(DISTINCT r.customer_id) AS customers
    FROM reservations r
    LEFT JOIN menus m ON r.menu_id = m.id
    WHERE r.status = 'completed' AND DATE(r.start_at) BETWEEN ? AND ?
");
$revMenu->execute([$startDate, $endDate]);
$revMenuData = $revMenu->fetch();

// 物販売上
$revProduct = $db->prepare("
    SELECT COALESCE(SUM(ps.price * ps.quantity), 0) AS total,
           COUNT(*) AS cnt
    FROM product_sales ps
    WHERE ps.sold_at BETWEEN ? AND ?
");
$revProduct->execute([$startDate, $endDate]);
$revProductData = $revProduct->fetch();

// 仕入原価（入荷時点で計上）
$costStmt = $db->prepare("
    SELECT COALESCE(SUM(total_cost), 0) AS cost
    FROM stock_purchases
    WHERE purchased_at BETWEEN ? AND ?
");
$costStmt->execute([$startDate, $endDate]);
$costData = $costStmt->fetch();

// 新規顧客数
$newCust = $db->prepare("SELECT COUNT(*) FROM customers WHERE DATE(created_at) BETWEEN ? AND ?");
$newCust->execute([$startDate, $endDate]);
$newCustCount = (int)$newCust->fetchColumn();

// 経費
$expenses = $db->prepare("SELECT * FROM expenses WHERE year=? AND month=? ORDER BY paid_at DESC");
$expenses->execute([$year, $month]);
$expenses = $expenses->fetchAll();
$totalExpense = array_sum(array_column($expenses, 'amount'));

// 計算
$revenueMenu    = (int)$revMenuData['total'];
$revenueProduct = (int)$revProductData['total'];
$revenueTotal   = $revenueMenu + $revenueProduct;
$costProduct    = (int)$costData['cost'];
$grossProfit    = $revenueTotal - $costProduct - $totalExpense;
$visitCount     = (int)$revMenuData['visits'];
$customerCount  = (int)$revMenuData['customers'];

// 前年同月KPI比較用
$prevMonthYear  = ($month >= 4) ? $year - 1 : $year - 1;
$prevMonthMonth = $month;
$prevMS = sprintf('%04d-%02d-01', $prevMonthYear, $prevMonthMonth);
$prevME = date('Y-m-t', strtotime($prevMS));

$pmRevMenu = $db->prepare("SELECT COALESCE(SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0)),0) AS total, COUNT(DISTINCT r.id) AS visits FROM reservations r LEFT JOIN menus m ON r.menu_id=m.id WHERE r.status='completed' AND DATE(r.start_at) BETWEEN ? AND ?");
$pmRevMenu->execute([$prevMS, $prevME]);
$pmRevMenuData = $pmRevMenu->fetch();

$pmRevProduct = $db->prepare("SELECT COALESCE(SUM(ps.price*ps.quantity),0) AS total FROM product_sales ps WHERE ps.sold_at BETWEEN ? AND ?");
$pmRevProduct->execute([$prevMS, $prevME]);
$pmRevProductData = $pmRevProduct->fetch();

$pmExpense = $db->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE year=? AND month=?");
$pmExpense->execute([$prevMonthYear, $prevMonthMonth]);
$pmExpenseData = $pmExpense->fetch();

$pmMenuTotal    = (int)$pmRevMenuData['total'];
$pmProductTotal = (int)$pmRevProductData['total'];
$pmTotal        = $pmMenuTotal + $pmProductTotal;
$pmExpenseTotal = (int)$pmExpenseData['total'];
$pmGrossProfit  = $pmTotal - $pmExpenseTotal;

function yoyBadge(int $current, int $prev): string {
    if ($prev === 0) return '';
    $rate = round(($current - $prev) / $prev * 100, 1);
    $color = $rate >= 0 ? '#27ae60' : '#e74c3c';
    $arrow = $rate >= 0 ? '▲' : '▼';
    return "<span style=\"font-size:0.75em;color:{$color};display:block;margin-top:3px;\">{$arrow}" . abs($rate) . "%</span>";
}

// 物販明細
$salesDetail = $db->prepare("
    SELECT ps.*, p.name AS product_name, p.maker, p.category,
           c.name AS customer_name
    FROM product_sales ps
    JOIN products p ON ps.product_id = p.id
    JOIN customers c ON ps.customer_id = c.id
    WHERE ps.sold_at BETWEEN ? AND ?
    ORDER BY ps.sold_at DESC
");
$salesDetail->execute([$startDate, $endDate]);
$salesDetail = $salesDetail->fetchAll();

// 4月始まり：当月が4月以降なら当年4月〜、3月以前なら前年4月〜（年次集計で使用）
$fiscalStartYear = ($month >= 4) ? $year : $year - 1;
$prevFiscalStartYear = $fiscalStartYear - 1;

// ============================================================
// ============================================================
// 日次集計（今日の詳細）
// ============================================================
// 日次表示日（GETパラメータ day または date_pick があればその日、なければ今日）
$dailyDate = date('Y-m-d');
if (!empty($_GET['day']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'])) {
    $dailyDate = $_GET['day'];
} elseif (!empty($_GET['date_pick']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_pick'])) {
    $dailyDate = $_GET['date_pick'];
}
$today     = $dailyDate;
$todayNext = date('Y-m-d', strtotime($dailyDate . ' +1 day'));
$dailyDay  = (int)date('d', strtotime($dailyDate));

// 今日の施術一覧（completed + confirmed）
$todayMenuStmt = $db->prepare("
    SELECT r.id, r.start_at, r.status, c.name AS customer_name,
           COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.menu')), m.name) AS menu_name, COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0) AS price,
           s.name AS staff_name
    FROM reservations r
    LEFT JOIN menus m ON r.menu_id = m.id
    JOIN customers c ON r.customer_id = c.id
    LEFT JOIN staff s ON r.staff_id = s.id
    WHERE r.status IN ('completed','confirmed') AND DATE(r.start_at) = ?
    ORDER BY r.start_at
");
$todayMenuStmt->execute([$today]);
$todayMenuList = $todayMenuStmt->fetchAll();

// 今日の物販一覧
$todayProductStmt = $db->prepare("
    SELECT ps.sold_at, ps.quantity, ps.price,
           p.name AS product_name, p.maker,
           c.name AS customer_name, ps.customer_id,
           r.id AS reservation_id,
           s.name AS staff_name
    FROM product_sales ps
    JOIN products p ON ps.product_id = p.id
    JOIN customers c ON ps.customer_id = c.id
    LEFT JOIN reservations r ON ps.reservation_id = r.id
    LEFT JOIN staff s ON r.staff_id = s.id
    WHERE DATE(ps.sold_at) = ?
    ORDER BY ps.sold_at
");
$todayProductStmt->execute([$today]);
$todayProductList = $todayProductStmt->fetchAll();

// 今日のKPI集計（売上はcompletedのみ）
$todayCompletedList = array_filter($todayMenuList, fn($r) => $r['status'] === 'completed');
$todayMenuSales    = array_sum(array_column($todayCompletedList, 'price'));
$todayProductSales = array_sum(array_map(fn($r) => $r['price'] * $r['quantity'], $todayProductList));
$todayProductQty   = array_sum(array_column($todayProductList, 'quantity'));
$todayVisits       = count($todayCompletedList);
$todayTotal        = $todayMenuSales + $todayProductSales;

// 月次：日別サマリー（当月全日）
$dailyMenuStmt = $db->prepare("
    SELECT DATE(r.start_at) AS day,
           COALESCE(SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0)), 0) AS menu_sales,
           COUNT(DISTINCT r.id) AS visits
    FROM reservations r
    LEFT JOIN menus m ON r.menu_id = m.id
    WHERE r.status = 'completed' AND DATE(r.start_at) BETWEEN ? AND ?
    GROUP BY DATE(r.start_at)
");
$dailyMenuStmt->execute([$startDate, $endDate]);
$dailyMenuMap = [];
foreach ($dailyMenuStmt->fetchAll() as $row) $dailyMenuMap[$row['day']] = $row;

$dailyProductStmt = $db->prepare("
    SELECT DATE(ps.sold_at) AS day, COALESCE(SUM(ps.price * ps.quantity), 0) AS product_sales
    FROM product_sales ps
    WHERE ps.sold_at BETWEEN ? AND ?
    GROUP BY DATE(ps.sold_at)
");
$dailyProductStmt->execute([$startDate, $endDate]);
$dailyProductMap = [];
foreach ($dailyProductStmt->fetchAll() as $row) $dailyProductMap[$row['day']] = (int)$row['product_sales'];

// 前年同月データ（日別サマリー右列用）
$prevYear      = $year - 1;
$prevStartDate = sprintf('%04d-%02d-01', $prevYear, $month);
$prevEndDate   = date('Y-m-t', strtotime($prevStartDate));

$prevDailyMenuStmt = $db->prepare("
    SELECT DATE(r.start_at) AS day,
           COALESCE(SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0)), 0) AS menu_sales,
           COUNT(DISTINCT r.id) AS visits
    FROM reservations r
    LEFT JOIN menus m ON r.menu_id = m.id
    WHERE r.status = 'completed' AND DATE(r.start_at) BETWEEN ? AND ?
    GROUP BY DATE(r.start_at)
");
$prevDailyMenuStmt->execute([$prevStartDate, $prevEndDate]);
$prevDailyMenuMap = [];
foreach ($prevDailyMenuStmt->fetchAll() as $row) $prevDailyMenuMap[$row['day']] = $row;

$prevDailyProductStmt = $db->prepare("
    SELECT DATE(ps.sold_at) AS day, COALESCE(SUM(ps.price * ps.quantity), 0) AS product_sales
    FROM product_sales ps
    WHERE ps.sold_at BETWEEN ? AND ?
    GROUP BY DATE(ps.sold_at)
");
$prevDailyProductStmt->execute([$prevStartDate, $prevEndDate]);
$prevDailyProductMap = [];
foreach ($prevDailyProductStmt->fetchAll() as $row) $prevDailyProductMap[$row['day']] = (int)$row['product_sales'];

// ============================================================
// 日別進捗グラフ用データ（当月の日次売上＋累計、前年同月の累計）
// ============================================================
$chartDaily  = [];
$daysInMonth = (int)date('t', strtotime($startDate));
$todayYmd    = date('Y-m-d');
$cum = 0; $prevCum = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $ds  = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $pds = sprintf('%04d-%02d-%02d', $prevYear, $month, min($d, (int)date('t', strtotime($prevStartDate))));
    $menuS    = (int)($dailyMenuMap[$ds]['menu_sales'] ?? 0);
    $productS = (int)($dailyProductMap[$ds] ?? 0);
    $cum     += $menuS + $productS;
    $prevCum += (int)($prevDailyMenuMap[$pds]['menu_sales'] ?? 0) + (int)($prevDailyProductMap[$pds] ?? 0);
    $chartDaily[] = [
        'day'      => $d,
        'menu'     => $menuS,
        'product'  => $productS,
        'cum'      => $ds > $todayYmd ? null : $cum,  // 未来日は累計線を引かない
        'prev_cum' => $prevCum,
    ];
}

// ============================================================
// 年次集計（当期・前期　4月始まり12ヶ月）
// ============================================================
$annualRows     = [];
$annualPrevRows = [];
$annualTotals     = ['menu'=>0,'product'=>0,'expense'=>0,'visits'=>0,'total'=>0,'profit'=>0];
$annualPrevTotals = ['menu'=>0,'product'=>0,'expense'=>0,'visits'=>0,'total'=>0,'profit'=>0];

for ($fi = 0; $fi < 12; $fi++) {
    // 当期
    $fm = (($fi + 3) % 12) + 1;
    $fy = $fiscalStartYear + (int)(($fi + 3) / 12);
    $sd = sprintf('%04d-%02d-01', $fy, $fm);
    $ed = date('Y-m-t', strtotime($sd));

    $ar1 = $db->prepare("SELECT COALESCE(SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0)),0) AS menu, COUNT(DISTINCT r.id) AS visits FROM reservations r LEFT JOIN menus m ON r.menu_id=m.id WHERE r.status='completed' AND DATE(r.start_at) BETWEEN ? AND ?");
    $ar1->execute([$sd,$ed]); $ar1d = $ar1->fetch();
    $ar2 = $db->prepare("SELECT COALESCE(SUM(ps.price*ps.quantity),0) AS product FROM product_sales ps WHERE ps.sold_at BETWEEN ? AND ?");
    $ar2->execute([$sd,$ed]); $ar2d = $ar2->fetch();
    $ar3 = $db->prepare("SELECT COALESCE(SUM(amount),0) AS expense FROM expenses WHERE year=? AND month=?");
    $ar3->execute([$fy,$fm]); $ar3d = $ar3->fetch();

    $rm = (int)$ar1d['menu']; $rp = (int)$ar2d['product']; $ex = (int)$ar3d['expense'];
    $vc = (int)$ar1d['visits']; $tot = $rm + $rp; $profit = $tot - $ex;
    $annualRows[$fi] = ['year'=>$fy,'month'=>$fm,'menu'=>$rm,'product'=>$rp,'expense'=>$ex,'visits'=>$vc,'total'=>$tot,'profit'=>$profit];
    $annualTotals['menu']    += $rm; $annualTotals['product'] += $rp;
    $annualTotals['expense'] += $ex; $annualTotals['visits']  += $vc;
    $annualTotals['total']   += $tot; $annualTotals['profit']  += $profit;

    // 前期
    $pfy = $fy - 1;
    $psd = sprintf('%04d-%02d-01', $pfy, $fm);
    $ped = date('Y-m-t', strtotime($psd));
    $pr1 = $db->prepare("SELECT COALESCE(SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.menu_snapshot,'$.price')) AS UNSIGNED), m.price, 0)),0) AS menu, COUNT(DISTINCT r.id) AS visits FROM reservations r LEFT JOIN menus m ON r.menu_id=m.id WHERE r.status='completed' AND DATE(r.start_at) BETWEEN ? AND ?");
    $pr1->execute([$psd,$ped]); $pr1d = $pr1->fetch();
    $pr2 = $db->prepare("SELECT COALESCE(SUM(ps.price*ps.quantity),0) AS product FROM product_sales ps WHERE ps.sold_at BETWEEN ? AND ?");
    $pr2->execute([$psd,$ped]); $pr2d = $pr2->fetch();
    $pr3 = $db->prepare("SELECT COALESCE(SUM(amount),0) AS expense FROM expenses WHERE year=? AND month=?");
    $pr3->execute([$pfy,$fm]); $pr3d = $pr3->fetch();

    $prm = (int)$pr1d['menu']; $prp = (int)$pr2d['product']; $pex = (int)$pr3d['expense'];
    $pvc = (int)$pr1d['visits']; $ptot = $prm + $prp; $pprofit = $ptot - $pex;
    $annualPrevRows[$fi] = ['year'=>$pfy,'month'=>$fm,'menu'=>$prm,'product'=>$prp,'expense'=>$pex,'visits'=>$pvc,'total'=>$ptot,'profit'=>$pprofit];
    $annualPrevTotals['menu']    += $prm; $annualPrevTotals['product'] += $prp;
    $annualPrevTotals['expense'] += $pex; $annualPrevTotals['visits']  += $pvc;
    $annualPrevTotals['total']   += $ptot; $annualPrevTotals['profit']  += $pprofit;
}

$expenseCategories = ['rent'=>'家賃','utility'=>'水道光熱費','salary'=>'人件費','purchase'=>'仕入','equipment'=>'備品','advertising'=>'広告費','other'=>'その他'];

$pageTitle = '月次集計';
include __DIR__ . '/_header.php';
?>

<div class="page-title">日時集計</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<!-- 月選択 -->
<div class="card">
    <div class="card-body" style="padding:14px 20px;">
        <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="date" name="date_pick" id="date-pick-top" value="<?= $dailyDate ?>"
                   style="padding:5px 8px;border:1px solid #ccc;border-radius:4px;font-size:0.95em;">
            <button class="btn btn-secondary btn-sm" type="submit">表示</button>
            <?php
            $prevY = $month == 1 ? $year-1 : $year; $prevM = $month == 1 ? 12 : $month-1;
            $nextY = $month == 12 ? $year+1 : $year; $nextM = $month == 12 ? 1 : $month+1;
            $prevDayTop = date('Y-m-d', strtotime($dailyDate . ' -1 day'));
            $nextDayTop = date('Y-m-d', strtotime($dailyDate . ' +1 day'));
            ?>
            <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>&day=<?= sprintf('%04d-%02d-%02d',$prevY,$prevM,min($dailyDay??1,date('t',mktime(0,0,0,$prevM,1,$prevY)))) ?>" class="btn btn-sm btn-secondary">＜前月</a>
            <a href="?year=<?= (int)date('Y',strtotime($prevDayTop)) ?>&month=<?= (int)date('m',strtotime($prevDayTop)) ?>&day=<?= $prevDayTop ?>" class="btn btn-sm btn-secondary">＜前日</a>
            <a href="?year=<?= (int)date('Y',strtotime($nextDayTop)) ?>&month=<?= (int)date('m',strtotime($nextDayTop)) ?>&day=<?= $nextDayTop ?>" class="btn btn-sm btn-secondary">翌日＞</a>
            <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>&day=<?= sprintf('%04d-%02d-%02d',$nextY,$nextM,min($dailyDay??1,date('t',mktime(0,0,0,$nextM,1,$nextY)))) ?>" class="btn btn-sm btn-secondary">翌月＞</a>
        </form>
    </div>
</div>

<h2 style="font-size:1.1em;color:#555;margin-bottom:14px;"><?= $year ?>年<?= $month ?>月の集計</h2>

<!-- タブ切り替え -->
<div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid #6B9E8A;">
    <button id="tab-daily"   onclick="switchTab('daily')"   style="padding:8px 24px;background:#6B9E8A;color:#fff;border:none;border-radius:6px 6px 0 0;font-weight:bold;cursor:pointer;font-size:0.95em;">📅 日次</button>
    <button id="tab-monthly" onclick="switchTab('monthly')" style="padding:8px 24px;background:#e8f5f0;color:#6B9E8A;border:none;border-radius:6px 6px 0 0;font-weight:bold;cursor:pointer;font-size:0.95em;margin-left:4px;">📊 月次</button>
    <button id="tab-yearly"  onclick="switchTab('yearly')"  style="padding:8px 24px;background:#e8f5f0;color:#6B9E8A;border:none;border-radius:6px 6px 0 0;font-weight:bold;cursor:pointer;font-size:0.95em;margin-left:4px;">📈 年次</button>
</div>

<!-- ======== 月次パネル ======== -->
<div id="panel-monthly" style="display:none;">

<!-- KPIカード -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;">
<?php
$kpis = [
    ['label'=>'総売上',  'value'=>'¥'.number_format($revenueTotal),   'color'=>'#6B9E8A','sub'=>'施術+物販',    'prev'=>$pmTotal],
    ['label'=>'施術売上','value'=>'¥'.number_format($revenueMenu),    'color'=>'#3498DB','sub'=>$visitCount.'件','prev'=>$pmMenuTotal],
    ['label'=>'物販売上','value'=>'¥'.number_format($revenueProduct), 'color'=>'#e67e22','sub'=>(int)$revProductData['cnt'].'件','prev'=>$pmProductTotal],
    ['label'=>'経費合計','value'=>'¥'.number_format($totalExpense),   'color'=>'#e74c3c','sub'=>count($expenses).'件','prev'=>$pmExpenseTotal],
    ['label'=>'粗利益',  'value'=>'¥'.number_format($grossProfit),    'color'=>$grossProfit>=0?'#27ae60':'#e74c3c','sub'=>'売上-原価-経費','prev'=>$pmGrossProfit],
];
foreach ($kpis as $k):
$curNum = (int)str_replace([' ','¥',','], '', $k['value']);
?>
<div class="stat-card" style="text-align:center;">
    <div style="font-size:1.4em;font-weight:bold;color:<?= $k['color'] ?>;"><?= $k['value'] ?></div>
    <?php if ($k['prev'] > 0): $rate = round(($curNum - $k['prev']) / $k['prev'] * 100, 1); $arrow = $rate >= 0 ? '▲' : '▼'; $rc = $rate >= 0 ? '#27ae60' : '#e74c3c'; ?>
    <div style="font-size:0.75em;color:<?= $rc ?>;font-weight:bold;"><?= $arrow.abs($rate) ?>%</div>
    <div style="font-size:0.75em;color:#aaa;">前年 ¥<?= number_format($k['prev']) ?></div>
    <?php endif; ?>
    <div style="font-size:0.82em;color:#888;margin-top:2px;"><?= $k['sub'] ?></div>
    <div style="font-size:0.85em;color:#555;margin-top:3px;font-weight:600;"><?= $k['label'] ?></div>
</div>
<?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
<!-- 来店サマリー -->
<div class="card">
    <div class="card-header">来店サマリー</div>
    <div class="card-body">
        <table style="font-size:0.9em;width:100%;">
            <tr><td style="color:#888;padding:6px 0;width:120px;">来店件数</td><td style="font-weight:bold;"><?= $visitCount ?>件</td></tr>
            <tr><td style="color:#888;padding:6px 0;">来店客数</td><td style="font-weight:bold;"><?= $customerCount ?>名</td></tr>
            <tr><td style="color:#888;padding:6px 0;">新規客数</td><td style="font-weight:bold;"><?= $newCustCount ?>名</td></tr>
            <tr><td style="color:#888;padding:6px 0;">客単価</td><td style="font-weight:bold;">¥<?= $visitCount > 0 ? number_format((int)($revenueTotal / $visitCount)) : 0 ?></td></tr>
            <tr><td style="color:#888;padding:6px 0;">仕入原価</td><td>¥<?= number_format($costProduct) ?> <span style="color:#888;font-size:0.82em;">（入荷時計上）</span></td></tr>
        </table>
    </div>
</div>

<!-- 経費入力 -->
<div class="card">
    <div class="card-header">
        経費・支払
        <button class="btn btn-sm btn-primary" onclick="toggleSection('addExpense')">＋ 追加</button>
    </div>
    <div id="addExpense" style="display:none;padding:14px;background:#f8f9fa;border-bottom:1px solid #eee;">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add_expense">
            <div style="display:grid;grid-template-columns:1fr 2fr 100px 100px auto;gap:10px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>カテゴリ</label>
                    <select name="category"><?php foreach ($expenseCategories as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group" style="margin:0;"><label>内容</label><input type="text" name="description" required placeholder="例：〇〇電気代"></div>
                <div class="form-group" style="margin:0;"><label>金額（円）</label><input type="number" name="amount" min="0" required></div>
                <div class="form-group" style="margin:0;"><label>支払日</label><input type="date" name="paid_at" value="<?= $startDate ?>"></div>
                <button class="btn btn-primary btn-sm" type="submit">追加</button>
            </div>
            <div class="form-group" style="margin-top:8px;"><label>備考</label><input type="text" name="note" placeholder="任意"></div>
        </form>
    </div>
    <div style="padding:0;max-height:220px;overflow-y:auto;">
        <table>
            <tr><th>日付</th><th>カテゴリ</th><th>内容</th><th>金額</th><th></th></tr>
            <?php foreach ($expenses as $e): ?>
            <tr>
                <td style="font-size:0.85em;white-space:nowrap;"><?= h(date('m/d', strtotime($e['paid_at']))) ?></td>
                <td style="font-size:0.82em;color:#888;"><?= h($expenseCategories[$e['category']] ?? $e['category']) ?></td>
                <td style="font-size:0.88em;"><?= h($e['description']) ?></td>
                <td style="font-weight:bold;">¥<?= number_format($e['amount']) ?></td>
                <td>
                    <form method="post" style="display:inline;" onsubmit="return confirm('削除しますか？')">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="delete_expense">
                        <input type="hidden" name="id" value="<?= $e['id'] ?>">
                        <button class="btn btn-danger btn-sm">削除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($expenses)): ?><tr><td colspan="5" style="text-align:center;padding:16px;color:#888;">経費がありません</td></tr><?php endif; ?>
        </table>
    </div>
    <?php if (!empty($expenses)): ?>
    <div style="padding:10px 16px;text-align:right;font-weight:bold;border-top:1px solid #eee;">合計：¥<?= number_format($totalExpense) ?></div>
    <?php endif; ?>
</div>
</div>

<!-- 日別進捗グラフ（当月） -->
<div class="card">
    <div class="card-header"><?= $year ?>年<?= $month ?>月　日別進捗 <span style="font-size:0.82em;color:#aaa;font-weight:normal;">／ 実線：当月累計　破線：前年同月累計</span></div>
    <div class="card-body">
        <canvas id="dailyChart" height="80"></canvas>
    </div>
</div>

<!-- 日別サマリー 2カラム -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

<!-- 左：当月 -->
<div class="card">
    <div class="card-header"><?= $year ?>年<?= $month ?>月　日別 <span style="font-size:0.8em;color:#aaa;font-weight:normal;">／ <span style="color:#e74c3c;">▲</span>=前年同日を下回った日</span></div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:0.85em;">
        <thead>
            <tr style="background:#f4f6f8;">
                <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #ddd;white-space:nowrap;">日付</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #ddd;">総売上</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #ddd;">施術</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #ddd;">物販</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #ddd;">客数</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #ddd;">単価</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $daysInMonth = (int)date('t', strtotime($startDate));
        $grandTotal  = ['sales'=>0,'menu'=>0,'product'=>0,'visits'=>0];
        for ($d = 1; $d <= $daysInMonth; $d++):
            $dateStr    = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $dow        = (int)date('w', strtotime($dateStr));
            $isToday    = ($dateStr === date('Y-m-d'));
            $rowBg      = $dow === 0 ? '#fff0f3' : ($dow === 6 ? '#f0f4ff' : ($isToday ? '#fffff0' : '#fff'));
            $dowLabel   = ['日','月','火','水','木','金','土'][$dow];
            $dowColor   = $dow === 0 ? 'color:#e74c3c;' : ($dow === 6 ? 'color:#3498db;' : '');
            $menuRow    = $dailyMenuMap[$dateStr]    ?? ['menu_sales'=>0,'visits'=>0];
            $productSal = $dailyProductMap[$dateStr] ?? 0;
            $totalSales = (int)$menuRow['menu_sales'] + $productSal;
            $visits     = (int)$menuRow['visits'];
            $unitPrice  = $visits > 0 ? (int)($totalSales / $visits) : 0;

            // 前年同日比：下回ったら▲（同等以上は無印、未来日は判定しない）
            $prevSameDay   = sprintf('%04d-%02d-%02d', $prevYear, $month, $d);
            $prevSameTotal = (int)(($prevDailyMenuMap[$prevSameDay]['menu_sales'] ?? 0)) + (int)($prevDailyProductMap[$prevSameDay] ?? 0);
            $isDown        = ($dateStr <= date('Y-m-d')) && ($totalSales < $prevSameTotal);
            $grandTotal['sales']   += $totalSales;
            $grandTotal['menu']    += (int)$menuRow['menu_sales'];
            $grandTotal['product'] += $productSal;
            $grandTotal['visits']  += $visits;
        ?>
        <tr style="border-bottom:1px solid #eee;background:<?= $rowBg ?>;cursor:pointer;"
            onclick="gotoDaily('<?= $dateStr ?>')"
            onmouseover="this.style.opacity='0.75'" onmouseout="this.style.opacity='1'">
            <td style="padding:5px 8px;white-space:nowrap;font-weight:<?= ($dow===0||$dow===6||$isToday)?'bold':'normal' ?>;<?= $dowColor ?><?= $isToday?'text-decoration:underline;':'' ?>">
                <?= $month ?>/<?= $d ?>（<?= $dowLabel ?>）<?= $isToday ? '◀' : '' ?>
            </td>
            <td style="padding:5px 8px;text-align:right;font-weight:bold;"><?php
                if ($isDown) echo '<span style="color:#e74c3c;font-size:0.85em;margin-right:2px;" title="前年同日 ¥' . number_format($prevSameTotal) . ' を下回っています">▲</span>';
                echo $totalSales > 0 ? '¥'.number_format($totalSales) : '<span style="color:#ddd;">—</span>';
            ?></td>
            <td style="padding:5px 8px;text-align:right;color:#3498db;"><?= (int)$menuRow['menu_sales'] > 0 ? '¥'.number_format((int)$menuRow['menu_sales']) : '<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#e67e22;"><?= $productSal > 0 ? '¥'.number_format($productSal) : '<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;"><?= $visits > 0 ? $visits.'名' : '<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;"><?= $unitPrice > 0 ? '¥'.number_format($unitPrice) : '<span style="color:#ddd;">—</span>' ?></td>
        </tr>
        <?php endfor; ?>
        </tbody>
        <tfoot>
        <?php $grandUnit = $grandTotal['visits'] > 0 ? (int)($grandTotal['sales']/$grandTotal['visits']) : 0; ?>
        <tr style="background:#f0faf4;font-weight:bold;border-top:2px solid #6B9E8A;">
            <td style="padding:7px 8px;">合計</td>
            <td style="padding:7px 8px;text-align:right;color:#6B9E8A;">¥<?= number_format($grandTotal['sales']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#3498db;">¥<?= number_format($grandTotal['menu']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#e67e22;">¥<?= number_format($grandTotal['product']) ?></td>
            <td style="padding:7px 8px;text-align:right;"><?= $grandTotal['visits'] ?>名</td>
            <td style="padding:7px 8px;text-align:right;">¥<?= number_format($grandUnit) ?></td>
        </tr>
        </tfoot>
    </table>
    </div>
</div>

<!-- 右：前年同月 -->
<div class="card">
    <div class="card-header"><?= $prevYear ?>年<?= $month ?>月　日別（前年）</div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:0.85em;">
        <thead>
            <tr style="background:#edf1f9;">
                <th style="padding:6px 8px;text-align:left;border-bottom:2px solid #aab8d8;white-space:nowrap;">日付</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #aab8d8;">総売上</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #aab8d8;">施術</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #aab8d8;">物販</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #aab8d8;">客数</th>
                <th style="padding:6px 8px;text-align:right;border-bottom:2px solid #aab8d8;">単価</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $prevDaysInMonth = (int)date('t', strtotime($prevStartDate));
        $prevGrandTotal  = ['sales'=>0,'menu'=>0,'product'=>0,'visits'=>0];
        for ($d = 1; $d <= $prevDaysInMonth; $d++):
            $pDateStr    = sprintf('%04d-%02d-%02d', $prevYear, $month, $d);
            $dow         = (int)date('w', strtotime($pDateStr));
            $rowBg       = $dow === 0 ? '#fff0f3' : ($dow === 6 ? '#f0f4ff' : '#fff');
            $dowLabel    = ['日','月','火','水','木','金','土'][$dow];
            $dowColor    = $dow === 0 ? 'color:#e74c3c;' : ($dow === 6 ? 'color:#3498db;' : '');
            $pMenuRow    = $prevDailyMenuMap[$pDateStr]    ?? ['menu_sales'=>0,'visits'=>0];
            $pProductSal = $prevDailyProductMap[$pDateStr] ?? 0;
            $pTotal      = (int)$pMenuRow['menu_sales'] + $pProductSal;
            $pVisits     = (int)$pMenuRow['visits'];
            $pUnit       = $pVisits > 0 ? (int)($pTotal / $pVisits) : 0;
            $prevGrandTotal['sales']   += $pTotal;
            $prevGrandTotal['menu']    += (int)$pMenuRow['menu_sales'];
            $prevGrandTotal['product'] += $pProductSal;
            $prevGrandTotal['visits']  += $pVisits;
        ?>
        <tr style="border-bottom:1px solid #eee;background:<?= $rowBg ?>;cursor:pointer;"
            onclick="gotoDaily('<?= $pDateStr ?>')"
            onmouseover="this.style.opacity='0.75'" onmouseout="this.style.opacity='1'">
            <td style="padding:5px 8px;white-space:nowrap;font-weight:<?= ($dow===0||$dow===6)?'bold':'normal' ?>;<?= $dowColor ?>">
                <?= $month ?>/<?= $d ?>（<?= $dowLabel ?>）
            </td>
            <td style="padding:5px 8px;text-align:right;color:#555;"><?= $pTotal > 0 ? '¥'.number_format($pTotal) : '<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#7fb3d3;"><?= (int)$pMenuRow['menu_sales'] > 0 ? '¥'.number_format((int)$pMenuRow['menu_sales']) : '<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#d4a574;"><?= $pProductSal > 0 ? '¥'.number_format($pProductSal) : '<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#888;"><?= $pVisits > 0 ? $pVisits.'名' : '<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#888;"><?= $pUnit > 0 ? '¥'.number_format($pUnit) : '<span style="color:#ddd;">—</span>' ?></td>
        </tr>
        <?php endfor; ?>
        </tbody>
        <tfoot>
        <?php $prevGrandUnit = $prevGrandTotal['visits'] > 0 ? (int)($prevGrandTotal['sales']/$prevGrandTotal['visits']) : 0; ?>
        <tr style="background:#e8eef8;font-weight:bold;border-top:2px solid #aab8d8;">
            <td style="padding:7px 8px;">合計</td>
            <td style="padding:7px 8px;text-align:right;color:#4a6fa5;">¥<?= number_format($prevGrandTotal['sales']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#7fb3d3;">¥<?= number_format($prevGrandTotal['menu']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#d4a574;">¥<?= number_format($prevGrandTotal['product']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#888;"><?= $prevGrandTotal['visits'] ?>名</td>
            <td style="padding:7px 8px;text-align:right;color:#888;">¥<?= number_format($prevGrandUnit) ?></td>
        </tr>
        </tfoot>
    </table>
    </div>
</div>

</div><!-- /2カラム -->

</div><!-- /panel-monthly -->

<!-- ======== 年次パネル ======== -->
<div id="panel-yearly" style="display:none;">

<?php
function yoyCell(int $cur, int $prev): string {
    if ($prev === 0) return '<span style="color:#aaa;">—</span>';
    $rate = round(($cur - $prev) / $prev * 100, 1);
    $color = $rate >= 0 ? '#27ae60' : '#e74c3c';
    $arrow = $rate >= 0 ? '▲' : '▼';
    return "<span style=\"font-size:0.8em;color:{$color};\">{$arrow}".abs($rate)."%</span>";
}
// 年次：来店・経費集計
$annualTotals['unit'] = $annualTotals['visits'] > 0 ? (int)($annualTotals['total'] / $annualTotals['visits']) : 0;
$annualPrevTotals['unit'] = $annualPrevTotals['visits'] > 0 ? (int)($annualPrevTotals['total'] / $annualPrevTotals['visits']) : 0;
?>

<!-- 年次KPIカード（7項目） -->
<div style="display:grid;grid-template-columns:repeat(7,1fr);gap:10px;margin-bottom:20px;">
<?php
$annualKpis = [
    ['label'=>'総売上',   'cur'=>$annualTotals['total'],   'prev'=>$annualPrevTotals['total'],   'color'=>'#6B9E8A'],
    ['label'=>'施術売上', 'cur'=>$annualTotals['menu'],    'prev'=>$annualPrevTotals['menu'],    'color'=>'#3498DB'],
    ['label'=>'物販売上', 'cur'=>$annualTotals['product'], 'prev'=>$annualPrevTotals['product'], 'color'=>'#e67e22'],
    ['label'=>'経費合計', 'cur'=>$annualTotals['expense'], 'prev'=>$annualPrevTotals['expense'], 'color'=>'#e74c3c'],
    ['label'=>'粗利益',   'cur'=>$annualTotals['profit'],  'prev'=>$annualPrevTotals['profit'],  'color'=>$annualTotals['profit']>=0?'#27ae60':'#e74c3c', 'yen'=>true],
    ['label'=>'来店客数', 'cur'=>$annualTotals['visits'],  'prev'=>$annualPrevTotals['visits'],  'color'=>'#8e44ad', 'unit'=>'名', 'yen'=>false],
    ['label'=>'客単価',   'cur'=>$annualTotals['unit'],    'prev'=>$annualPrevTotals['unit'],    'color'=>'#555', 'yen'=>true],
];
foreach ($annualKpis as $k):
    $isYen = ($k['yen'] ?? true);
    $valStr = $isYen ? '¥'.number_format($k['cur']) : number_format($k['cur']).($k['unit']??'');
    $prevStr = $isYen ? '¥'.number_format($k['prev']) : number_format($k['prev']).($k['unit']??'');
    $rate = $k['prev'] > 0 ? round(($k['cur'] - $k['prev']) / $k['prev'] * 100, 1) : null;
    $arrow = ($rate !== null && $rate >= 0) ? '▲' : '▼';
    $rc    = ($rate !== null && $rate >= 0) ? '#27ae60' : '#e74c3c';
?>
<div class="stat-card" style="text-align:center;padding:12px 8px;">
    <div style="font-size:1.15em;font-weight:bold;color:<?= $k['color'] ?>;"><?= $valStr ?></div>
    <?php if ($rate !== null): ?>
    <div style="font-size:0.72em;color:<?= $rc ?>;font-weight:bold;"><?= $arrow.abs($rate) ?>%</div>
    <div style="font-size:0.7em;color:#aaa;">前期<?= $prevStr ?></div>
    <?php endif; ?>
    <div style="font-size:0.8em;color:#555;margin-top:4px;font-weight:600;"><?= $k['label'] ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- グラフ -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <?= $fiscalStartYear ?>年4月〜<?= $fiscalStartYear+1 ?>年3月　月次推移
        <span style="font-size:0.82em;color:#aaa;font-weight:normal;">折れ線：前期（<?= $fiscalStartYear-1 ?>年4月〜<?= $fiscalStartYear ?>年3月）</span>
    </div>
    <div class="card-body">
        <canvas id="annualChart" height="90"></canvas>
    </div>
</div>

<!-- 月別 2カラム表 -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">

<?php
// テーブル共通ヘッダー
function annualTableHeader(string $bg, string $border, string $thColor): void { ?>
<thead>
    <tr style="background:<?= $bg ?>;">
        <th style="padding:6px 8px;text-align:left;border-bottom:2px solid <?= $border ?>;white-space:nowrap;color:<?= $thColor ?>;">月</th>
        <th style="padding:6px 8px;text-align:right;border-bottom:2px solid <?= $border ?>;color:<?= $thColor ?>;">総売上</th>
        <th style="padding:6px 8px;text-align:right;border-bottom:2px solid <?= $border ?>;color:<?= $thColor ?>;">施術</th>
        <th style="padding:6px 8px;text-align:right;border-bottom:2px solid <?= $border ?>;color:<?= $thColor ?>;">物販</th>
        <th style="padding:6px 8px;text-align:right;border-bottom:2px solid <?= $border ?>;color:<?= $thColor ?>;">経費</th>
        <th style="padding:6px 8px;text-align:right;border-bottom:2px solid <?= $border ?>;color:<?= $thColor ?>;">粗利</th>
        <th style="padding:6px 8px;text-align:right;border-bottom:2px solid <?= $border ?>;color:<?= $thColor ?>;">客数</th>
        <th style="padding:6px 8px;text-align:right;border-bottom:2px solid <?= $border ?>;color:<?= $thColor ?>;">昨対</th>
    </tr>
</thead>
<?php }
?>

<!-- 左：進行期 -->
<div class="card">
    <div class="card-header"><?= $fiscalStartYear ?>年4月〜<?= $fiscalStartYear+1 ?>年3月</div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:0.83em;">
        <?php annualTableHeader('#f4f6f8','#6B9E8A','#555'); ?>
        <tbody>
        <?php foreach ($annualRows as $fi => $row):
            $prev = $annualPrevRows[$fi];
            $isThisMonth = ($row['year'] === $year && $row['month'] === $month);
            $isFuture    = ($row['year'] > date('Y') || ($row['year'] == date('Y') && $row['month'] > date('n')));
            $rowBg = $isThisMonth ? '#fffff0' : ($isFuture ? '#fafafa' : '#fff');
            $tc    = $isFuture ? '#ccc' : '#333';
        ?>
        <tr style="border-bottom:1px solid #eee;background:<?= $rowBg ?>;">
            <td style="padding:5px 8px;white-space:nowrap;font-weight:<?= $isThisMonth?'bold':'normal' ?>;color:<?= $tc ?>;">
                <?= $row['month'] ?>月<?php if ($isThisMonth): ?> <span style="background:#6B9E8A;color:#fff;font-size:0.7em;padding:1px 5px;border-radius:8px;">今月</span><?php endif; ?>
            </td>
            <td style="padding:5px 8px;text-align:right;font-weight:bold;color:<?= $isFuture?'#ccc':'#6B9E8A' ?>;"><?= $row['total']>0?'¥'.number_format($row['total']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:<?= $isFuture?'#ccc':'#3498db' ?>;"><?= $row['menu']>0?'¥'.number_format($row['menu']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:<?= $isFuture?'#ccc':'#e67e22' ?>;"><?= $row['product']>0?'¥'.number_format($row['product']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:<?= $isFuture?'#ccc':'#e74c3c' ?>;"><?= $row['expense']>0?'¥'.number_format($row['expense']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:<?= $row['profit']>=0?($isFuture?'#ccc':'#27ae60'):'#e74c3c' ?>;"><?= !$isFuture&&($row['total']>0||$row['expense']>0)?'¥'.number_format($row['profit']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:<?= $isFuture?'#ccc':'#555' ?>;"><?= $row['visits']>0?$row['visits'].'名':'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;"><?= $isFuture?'':yoyCell($row['total'],$prev['total']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <?php $annualUnit = $annualTotals['visits']>0?(int)($annualTotals['total']/$annualTotals['visits']):0; ?>
        <tr style="background:#f0faf4;font-weight:bold;border-top:2px solid #6B9E8A;">
            <td style="padding:7px 8px;">合計</td>
            <td style="padding:7px 8px;text-align:right;color:#6B9E8A;">¥<?= number_format($annualTotals['total']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#3498db;">¥<?= number_format($annualTotals['menu']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#e67e22;">¥<?= number_format($annualTotals['product']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#e74c3c;">¥<?= number_format($annualTotals['expense']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:<?= $annualTotals['profit']>=0?'#27ae60':'#e74c3c' ?>;">¥<?= number_format($annualTotals['profit']) ?></td>
            <td style="padding:7px 8px;text-align:right;"><?= $annualTotals['visits'] ?>名</td>
            <td style="padding:7px 8px;text-align:right;"><?= yoyCell($annualTotals['total'],$annualPrevTotals['total']) ?></td>
        </tr>
        </tfoot>
    </table>
    </div>
</div>

<!-- 右：昨季 -->
<div class="card">
    <div class="card-header" style="background:#edf1f9;"><?= $fiscalStartYear-1 ?>年4月〜<?= $fiscalStartYear ?>年3月（前期）</div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:0.83em;">
        <?php annualTableHeader('#edf1f9','#aab8d8','#666'); ?>
        <tbody>
        <?php foreach ($annualPrevRows as $fi => $prev):
            $cur = $annualRows[$fi];
            $ppfi_sd = sprintf('%04d-%02d-01', $prev['year']-1, $prev['month']);
            // 前前期データ（昨対用）は簡易で0扱い
            $rowBg = '#fff';
        ?>
        <tr style="border-bottom:1px solid #eee;background:<?= $rowBg ?>;">
            <td style="padding:5px 8px;white-space:nowrap;color:#555;"><?= $prev['month'] ?>月</td>
            <td style="padding:5px 8px;text-align:right;font-weight:bold;color:#4a6fa5;"><?= $prev['total']>0?'¥'.number_format($prev['total']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#7fb3d3;"><?= $prev['menu']>0?'¥'.number_format($prev['menu']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#d4a574;"><?= $prev['product']>0?'¥'.number_format($prev['product']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#e08080;"><?= $prev['expense']>0?'¥'.number_format($prev['expense']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:<?= $prev['profit']>=0?'#6aaf8a':'#e74c3c' ?>;"><?= ($prev['total']>0||$prev['expense']>0)?'¥'.number_format($prev['profit']):'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#888;"><?= $prev['visits']>0?$prev['visits'].'名':'<span style="color:#ddd;">—</span>' ?></td>
            <td style="padding:5px 8px;text-align:right;color:#aaa;font-size:0.8em;">—</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <?php $prevAnnualUnit = $annualPrevTotals['visits']>0?(int)($annualPrevTotals['total']/$annualPrevTotals['visits']):0; ?>
        <tr style="background:#e8eef8;font-weight:bold;border-top:2px solid #aab8d8;">
            <td style="padding:7px 8px;color:#555;">合計</td>
            <td style="padding:7px 8px;text-align:right;color:#4a6fa5;">¥<?= number_format($annualPrevTotals['total']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#7fb3d3;">¥<?= number_format($annualPrevTotals['menu']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#d4a574;">¥<?= number_format($annualPrevTotals['product']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#e08080;">¥<?= number_format($annualPrevTotals['expense']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:<?= $annualPrevTotals['profit']>=0?'#6aaf8a':'#e74c3c' ?>;">¥<?= number_format($annualPrevTotals['profit']) ?></td>
            <td style="padding:7px 8px;text-align:right;color:#888;"><?= $annualPrevTotals['visits'] ?>名</td>
            <td style="padding:7px 8px;text-align:right;color:#aaa;">—</td>
        </tr>
        </tfoot>
    </table>
    </div>
</div>

</div><!-- /2カラム -->

</div><!-- /panel-yearly -->

<!-- ======== 日次パネル ======== -->
<div id="panel-daily">

<?php
$dailyDateLabel = date('Y年m月d日', strtotime($dailyDate));
$dailyDow = ['日','月','火','水','木','金','土'][(int)date('w', strtotime($dailyDate))];
$isActualToday = ($dailyDate === date('Y-m-d'));
$prevDay    = date('Y-m-d', strtotime($dailyDate . ' -1 day'));
$nextDay    = date('Y-m-d', strtotime($dailyDate . ' +1 day'));
$prevDayUrl = '?year='.(int)date('Y',strtotime($prevDay)).'&month='.(int)date('m',strtotime($prevDay)).'&day='.$prevDay.'#daily';
$nextDayUrl = '?year='.(int)date('Y',strtotime($nextDay)).'&month='.(int)date('m',strtotime($nextDay)).'&day='.$nextDay.'#daily';
// 前月・翌月（同じ日付で月だけ移動、月末オーバー対策）
$prevMonthDay = date('Y-m-d', strtotime($dailyDate . ' -1 month'));
$nextMonthDay = date('Y-m-d', strtotime($dailyDate . ' +1 month'));
$prevMonthUrl = '?year='.(int)date('Y',strtotime($prevMonthDay)).'&month='.(int)date('m',strtotime($prevMonthDay)).'&day='.$prevMonthDay.'#daily';
$nextMonthUrl = '?year='.(int)date('Y',strtotime($nextMonthDay)).'&month='.(int)date('m',strtotime($nextMonthDay)).'&day='.$nextMonthDay.'#daily';
?>



<h2 style="font-size:1.05em;color:#555;margin-bottom:14px;">
    📅 <?= $dailyDateLabel ?>（<?= $dailyDow ?>）の集計
    <?php if ($isActualToday): ?><span style="background:#6B9E8A;color:#fff;font-size:0.8em;padding:2px 8px;border-radius:10px;margin-left:8px;">今日</span><?php endif; ?>
</h2>

<!-- 今日のKPI -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;">
    <?php
    $todayKpis = [
        ['label'=>'総売上',    'value'=>'¥'.number_format($todayTotal),       'color'=>'#6B9E8A'],
        ['label'=>'来店数',    'value'=>$todayVisits.'名',                     'color'=>'#3498DB'],
        ['label'=>'施術売上',  'value'=>'¥'.number_format($todayMenuSales),    'color'=>'#8e44ad'],
        ['label'=>'物販売上',  'value'=>'¥'.number_format($todayProductSales), 'color'=>'#e67e22'],
        ['label'=>'物販点数',  'value'=>$todayProductQty.'点',                 'color'=>'#27ae60'],
    ];
    foreach ($todayKpis as $k): ?>
    <div class="stat-card" style="text-align:center;">
        <div style="font-size:1.6em;font-weight:bold;color:<?= $k['color'] ?>;"><?= $k['value'] ?></div>
        <div style="font-size:0.85em;color:#555;margin-top:6px;font-weight:600;"><?= $k['label'] ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- 今日の施術一覧 -->
<?php
$completedCount = count(array_filter($todayMenuList, fn($r) => $r['status'] === 'completed'));
$confirmedCount = count(array_filter($todayMenuList, fn($r) => $r['status'] === 'confirmed'));
?>
<div class="card">
    <div class="card-header">
        ✂️ 施術一覧（<?= count($todayMenuList) ?>件
        <?php if ($confirmedCount > 0): ?>
        ─ 完了 <span style="color:#6B9E8A;font-weight:bold;"><?= $completedCount ?></span>件 ／ 予定 <span style="color:#3498db;font-weight:bold;"><?= $confirmedCount ?></span>件
        <?php endif; ?>
        ）
    </div>
    <div class="card-body" style="padding:0;">
    <?php if (empty($todayMenuList)): ?>
        <p style="padding:20px;color:#888;text-align:center;">施術の記録はありません</p>
    <?php else: ?>
        <table>
            <tr><th>時間</th><th>お客様</th><th>メニュー</th><th>金額</th><th>担当</th><th>状態</th></tr>
            <?php foreach ($todayMenuList as $r):
                $isDone = $r['status'] === 'completed';
                $rowStyle = $isDone ? '' : 'background:#f0f7ff;';
            ?>
            <tr style="<?= $rowStyle ?>">
                <td style="white-space:nowrap;color:#888;font-size:0.88em;"><?= h(date('H:i', strtotime($r['start_at']))) ?></td>
                <td><a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $r['id'] ?>" style="color:#3498db;text-decoration:none;font-weight:bold;"><?= h($r['customer_name']) ?>様</a></td>
                <td><?= h($r['menu_name']) ?></td>
                <td style="font-weight:bold;"><?= $isDone ? '¥'.number_format($r['price']) : '<span style="color:#aaa;">¥'.number_format($r['price']).'</span>' ?></td>
                <td style="color:#6B9E8A;"><?= h($r['staff_name'] ?? '未定') ?></td>
                <td>
                    <?php if ($isDone): ?>
                    <span style="background:#e8f5f0;color:#6B9E8A;font-size:0.78em;padding:2px 8px;border-radius:10px;font-weight:bold;">完了</span>
                    <?php else: ?>
                    <span style="background:#e8f0ff;color:#3498db;font-size:0.78em;padding:2px 8px;border-radius:10px;font-weight:bold;">予定</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#f0faf4;font-weight:bold;border-top:2px solid #eee;">
                <td colspan="3" style="padding:8px 12px;text-align:right;color:#888;">施術合計（完了分）</td>
                <td style="padding:8px 12px;color:#6B9E8A;">¥<?= number_format($todayMenuSales) ?></td>
                <td colspan="2"></td>
            </tr>
        </table>
    <?php endif; ?>
    </div>
</div>

<!-- 今日の物販一覧 -->
<div class="card">
    <div class="card-header">🛍️ 今日の物販（<?= $todayProductQty ?>点）</div>
    <div class="card-body" style="padding:0;">
    <?php if (empty($todayProductList)): ?>
        <p style="padding:20px;color:#888;text-align:center;">物販の記録はありません</p>
    <?php else: ?>
        <table>
            <tr><th>お客様</th><th>商品</th><th>数量</th><th>金額</th><th>担当</th></tr>
            <?php foreach ($todayProductList as $p): ?>
            <tr>
                <td>
                <?php if ($p['reservation_id']): ?>
                    <a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $p['reservation_id'] ?>" style="color:#3498db;text-decoration:none;font-weight:bold;"><?= h($p['customer_name']) ?>様</a>
                <?php else: ?>
                    <?= h($p['customer_name']) ?>様
                <?php endif; ?>
                </td>
                <td><?= h($p['product_name']) ?><?php if ($p['maker']): ?> <span style="color:#aaa;font-size:0.82em;">/<?= h($p['maker']) ?></span><?php endif; ?></td>
                <td><?= h($p['quantity']) ?>個</td>
                <td style="font-weight:bold;">¥<?= number_format($p['price'] * $p['quantity']) ?></td>
                <td style="color:#6B9E8A;"><?= h($p['staff_name'] ?? '未定') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:#fff8f0;font-weight:bold;border-top:2px solid #eee;">
                <td colspan="3" style="padding:8px 12px;text-align:right;color:#888;">物販合計</td>
                <td style="padding:8px 12px;color:#e67e22;">¥<?= number_format($todayProductSales) ?></td>
                <td></td>
            </tr>
        </table>
    <?php endif; ?>
    </div>
</div>

</div><!-- /panel-daily -->

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// 日別進捗グラフ（棒：日次売上 ／ 実線：当月累計 ／ 破線：前年同月累計）
const dailyChartData = <?= json_encode($chartDaily) ?>;
const labels      = dailyChartData.map(d => d.day + '日');
const menuData    = dailyChartData.map(d => d.menu);
const productData = dailyChartData.map(d => d.product);
const cumData     = dailyChartData.map(d => d.cum);
const prevCumData = dailyChartData.map(d => d.prev_cum);

const ctx = document.getElementById('dailyChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels,
        datasets: [
            {
                type: 'bar',
                label: '施術売上',
                data: menuData,
                backgroundColor: 'rgba(107,158,138,0.85)',
                stack: 'revenue',
                yAxisID: 'y'
            },
            {
                type: 'bar',
                label: '物販売上',
                data: productData,
                backgroundColor: 'rgba(230,126,34,0.85)',
                stack: 'revenue',
                yAxisID: 'y'
            },
            {
                type: 'line',
                label: '当月累計（進捗）',
                data: cumData,
                borderColor: 'rgba(46,113,84,1)',
                backgroundColor: 'rgba(107,158,138,0.08)',
                borderWidth: 2.5,
                pointRadius: 2,
                tension: 0.2,
                fill: true,
                spanGaps: false,
                yAxisID: 'y2',
                order: 0
            },
            {
                type: 'line',
                label: '前年同月累計',
                data: prevCumData,
                borderColor: 'rgba(100,120,200,0.75)',
                borderDash: [6, 4],
                borderWidth: 2,
                pointRadius: 0,
                tension: 0.2,
                yAxisID: 'y2',
                order: 0
            },
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        scales: {
            y: {
                beginAtZero: true,
                position: 'left',
                title: { display: true, text: '日次売上' },
                ticks: { callback: v => '¥' + v.toLocaleString() }
            },
            y2: {
                beginAtZero: true,
                position: 'right',
                grid: { drawOnChartArea: false },
                title: { display: true, text: '累計' },
                ticks: { callback: v => '¥' + v.toLocaleString() }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: ctx => ctx.dataset.label + ': ¥' + (ctx.raw ?? 0).toLocaleString()
                }
            },
            legend: { position: 'bottom' }
        }
    }
});

// 年次グラフ（annualChart）
const annualData     = <?= json_encode(array_values($annualRows)) ?>;
const annualPrevData = <?= json_encode(array_values($annualPrevRows)) ?>;
const annualLabels   = annualData.map(d => d.month + '月');
const annualCtx = document.getElementById('annualChart');
if (annualCtx) {
    new Chart(annualCtx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: annualLabels,
            datasets: [
                {
                    type: 'bar', label: '施術売上',
                    data: annualData.map(d => d.menu),
                    backgroundColor: 'rgba(107,158,138,0.85)', stack: 'rev'
                },
                {
                    type: 'bar', label: '物販売上',
                    data: annualData.map(d => d.product),
                    backgroundColor: 'rgba(230,126,34,0.85)', stack: 'rev'
                },
                {
                    type: 'bar', label: '経費',
                    data: annualData.map(d => d.expense),
                    backgroundColor: 'rgba(231,76,60,0.3)', stack: 'exp'
                },
                {
                    type: 'line', label: '前期売上',
                    data: annualPrevData.map(d => d.total),
                    borderColor: 'rgba(100,120,200,0.9)',
                    backgroundColor: 'rgba(100,120,200,0.1)',
                    borderWidth: 2, pointRadius: 4,
                    pointBackgroundColor: 'rgba(100,120,200,0.9)',
                    tension: 0.3, stack: undefined, order: 0
                },
                {
                    type: 'line', label: '来店数',
                    data: annualData.map(d => d.visits),
                    borderColor: 'rgba(142,68,173,0.8)',
                    backgroundColor: 'rgba(142,68,173,0.1)',
                    borderWidth: 2, pointRadius: 3,
                    tension: 0.3, yAxisID: 'y2', stack: undefined, order: 0
                },
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y:  { beginAtZero: true, position: 'left',  ticks: { callback: v => '¥' + v.toLocaleString() } },
                y2: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false },
                      ticks: { callback: v => v + '名' } }
            },
            plugins: {
                tooltip: { callbacks: { label: ctx => {
                    if (ctx.dataset.yAxisID === 'y2') return ctx.dataset.label + ': ' + ctx.raw + '名';
                    return ctx.dataset.label + ': ¥' + (ctx.raw||0).toLocaleString();
                }}},
                legend: { position: 'bottom' }
            }
        }
    });
}


function switchTab(tab) {
    ['daily','monthly','yearly'].forEach(t => {
        const panel = document.getElementById('panel-' + t);
        const btn   = document.getElementById('tab-' + t);
        if (!panel || !btn) return;
        const active = (t === tab);
        panel.style.display    = active ? '' : 'none';
        btn.style.background   = active ? '#6B9E8A' : '#e8f5f0';
        btn.style.color        = active ? '#fff'    : '#6B9E8A';
    });
}
function gotoDaily(dateStr) {
    const d = new Date(dateStr);
    const url = new URL(location.href);
    url.searchParams.set('day',   dateStr);
    url.searchParams.set('year',  d.getFullYear());
    url.searchParams.set('month', d.getMonth() + 1);
    url.hash = 'daily';
    location.href = url.toString();
}
// 上部フォームsubmit時にdayパラメータを組み立て
document.querySelector('form[method="get"]').addEventListener('submit', function(e) {
    e.preventDefault();
    const dateStr = document.getElementById('date-pick-top').value;
    if (!dateStr) return;
    const dt = new Date(dateStr);
    const url = new URL(location.href);
    url.searchParams.set('year',  dt.getFullYear());
    url.searchParams.set('month', dt.getMonth() + 1);
    url.searchParams.set('day',   dateStr);
    location.href = url.toString();
});

// dayパラメータがあれば日次タブを自動表示
(function() {
    const params = new URLSearchParams(location.search);
    if (params.has('day') || location.hash === '#daily') {
        switchTab('daily');
    }
})();

function toggleSection(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
