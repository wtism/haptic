<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$db = db();

// ── 日付ナビゲーション ──────────────────────────────
$viewDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewDate)) $viewDate = date('Y-m-d');
$today    = date('Y-m-d');   // 今日（統計用）
$month    = date('m', strtotime($viewDate));
$day      = date('d', strtotime($viewDate));
$prevDate = date('Y-m-d', strtotime($viewDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($viewDate . ' +1 day'));

// ── 新規予約POST処理 ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dashboard_add_reservation') {
    verifyCsrf(); // 失敗時は関数内でexit
    $customerId = (int)$_POST['customer_id'];
    $menuId     = (int)$_POST['menu_id'];
    $staffId    = $_POST['staff_id'] ? (int)$_POST['staff_id'] : null;
    $startAt    = $_POST['date'] . ' ' . $_POST['time'] . ':00';
    $note       = $_POST['note'] ?? '';
    $mr = $db->prepare('SELECT duration_min FROM menus WHERE id=?'); $mr->execute([$menuId]); $mr = $mr->fetch();
    $endAt = date('Y-m-d H:i:s', strtotime($startAt) + (($mr['duration_min'] ?? 60) * 60));
    $db->prepare('INSERT INTO reservations (customer_id, staff_id, menu_id, start_at, end_at, status, note, created_by) VALUES (?,?,?,?,?,"confirmed",?,?)')
       ->execute([$customerId, $staffId, $menuId, $startAt, $endAt, $note, currentAdminId()]);
    $newId = (int)$db->lastInsertId();
    auditLog('create', 'reservation', $newId, '管理画面から予約追加（ダッシュボード）');
    header('Location: ' . adminUrl('dashboard.php') . '?date=' . $_POST['date']);
    exit;
}

// メニュー・スタッフ一覧（新規予約モーダル用）
$menus    = $db->query('SELECT * FROM menus WHERE is_active=1 ORDER BY display_order')->fetchAll();
$staffAll = $db->query('SELECT * FROM staff WHERE is_active=1 ORDER BY display_order')->fetchAll();

// 在庫アラート（販売中のみ）
$stockAlerts = $db->query("
    SELECT * FROM products
    WHERE is_active=1 AND status='active' AND stock <= stock_alert
    ORDER BY item_type, stock ASC
    LIMIT 20
")->fetchAll();

// 本日の予約
$stmt = $db->prepare('
    SELECT r.*, c.name AS customer_name, c.line_user_id,
           s.name AS staff_name, s.id AS staff_id_val, m.name AS menu_name, m.duration_min
    FROM reservations r
    LEFT JOIN customers c ON r.customer_id = c.id
    LEFT JOIN staff s ON r.staff_id = s.id
    LEFT JOIN menus m ON r.menu_id = m.id
    WHERE DATE(r.start_at) = ? AND r.status != "cancelled"
    ORDER BY r.start_at
');
$stmt->execute([$viewDate]);
$todayReservations = $stmt->fetchAll();

// タイムテーブル用データ整理
$staffListAll = $db->query('SELECT * FROM staff WHERE is_active=1 ORDER BY display_order')->fetchAll();

// 本日の休日スタッフ取得
$offStmt = $db->prepare("SELECT staff_id, off_type FROM staff_days_off WHERE off_date=?");
$offStmt->execute([$viewDate]);
$todayOff = [];
foreach ($offStmt->fetchAll() as $o) $todayOff[$o['staff_id']] = $o['off_type'];

$shopOpen  = 9 * 60;
$shopClose = 20 * 60;
$slotMin   = 15;
$totalSlots = ($shopClose - $shopOpen) / $slotMin;

$timetable = [];
foreach ($todayReservations as $r) {
    $sid       = $r['staff_id_val'] ?? 'none';
    $startMin  = (int)date('H', strtotime($r['start_at'])) * 60 + (int)date('i', strtotime($r['start_at']));
    $endMin    = (int)date('H', strtotime($r['end_at']))   * 60 + (int)date('i', strtotime($r['end_at']));
    $startSlot = (int)(($startMin - $shopOpen) / $slotMin);
    $spanSlots = max(1, (int)(($endMin - $startMin) / $slotMin));
    $timetable[$sid][] = ['reservation'=>$r, 'start_slot'=>$startSlot, 'span_slots'=>$spanSlots];
}

// 統計
$stmt = $db->query('SELECT COUNT(*) FROM reservations WHERE status = "pending"');
$pendingCount = $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM reservations WHERE DATE(start_at) = ? AND status != "cancelled"');
$stmt->execute([$viewDate]);
$todayCount = $stmt->fetchColumn();

$stmt = $db->query('SELECT COUNT(*) FROM customers');
$customerCount = $stmt->fetchColumn();

// 未承認予約
$stmt = $db->query('
    SELECT r.*, c.name AS customer_name, c.line_user_id,
           s.name AS staff_name, m.name AS menu_name
    FROM reservations r
    LEFT JOIN customers c ON r.customer_id = c.id
    LEFT JOIN staff s ON r.staff_id = s.id
    LEFT JOIN menus m ON r.menu_id = m.id
    WHERE r.status = "pending"
    ORDER BY r.created_at DESC LIMIT 10
');
$pendingList = $stmt->fetchAll();

// ── 誕生日間近（当月表示・ページネーション） ──────────────
$bdayPage   = max(1, (int)($_GET['bdp'] ?? 1));
$bdayMonth  = (int)($_GET['bdm'] ?? (int)date('m'));
$bdayYear   = (int)($_GET['bdy'] ?? (int)date('Y'));
$bdayPerPage = 10;
$bdayOffset  = ($bdayPage - 1) * $bdayPerPage;

// CSV出力（該当月全件）
if (($_GET['action'] ?? '') === 'birthday_csv') {
    $csvRows = $db->query("
        SELECT c.name, c.birthdate, c.address, c.line_user_id,
               DATEDIFF(DATE_FORMAT(c.birthdate, CONCAT(YEAR(NOW()), '-%m-%d')), CURDATE()) AS days_until
        FROM customers c
        WHERE c.birthdate IS NOT NULL AND MONTH(c.birthdate) = {$bdayMonth}
        ORDER BY DAY(c.birthdate) ASC
    ")->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="birthday_' . $bdayYear . sprintf('%02d', $bdayMonth) . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM
    $out = fopen('php://output', 'w');
    fputcsv($out, ['お名前', '誕生日', 'あと何日', '住所', 'LINE登録']);
    foreach ($csvRows as $c) {
        fputcsv($out, [$c['name'], date('m月d日', strtotime($c['birthdate'])), $c['days_until'] >= 0 ? 'あと'.$c['days_until'].'日' : abs($c['days_until']).'日前', $c['address'] ?? '', $c['line_user_id'] ? '有' : '無']);
    }
    fclose($out); exit;
}

$todayDay   = (int)date('d');
$todayMonth = (int)date('m');
$isCurrentMonth = ($bdayMonth === $todayMonth);

// 当月：今日以降のみ（過去は表示しない）、日付昇順
// 他月：全件、日付昇順
$whereSql = $isCurrentMonth
    ? "c.birthdate IS NOT NULL AND MONTH(c.birthdate) = ? AND DAY(c.birthdate) >= {$todayDay}"
    : "c.birthdate IS NOT NULL AND MONTH(c.birthdate) = ?";

$bdayTotal = $db->prepare("SELECT COUNT(*) FROM customers c WHERE {$whereSql}");
$bdayTotal->execute([$bdayMonth]);
$bdayTotalCount = (int)$bdayTotal->fetchColumn();
$bdayTotalPages = max(1, (int)ceil($bdayTotalCount / $bdayPerPage));

$bdayStmt = $db->prepare("
    SELECT c.*,
           DATEDIFF(
               DATE_FORMAT(c.birthdate, CONCAT(YEAR(NOW()), '-%m-%d')),
               CURDATE()
           ) AS days_until
    FROM customers c
    WHERE {$whereSql}
    ORDER BY DAY(c.birthdate) ASC
    LIMIT {$bdayPerPage} OFFSET {$bdayOffset}
");
$bdayStmt->execute([$bdayMonth]);
$birthdayList = $bdayStmt->fetchAll();

// ── 物販リマインド（購入から2ヶ月後） ──────────────────────
$remindList = $db->query("
    SELECT ps.*, c.name AS customer_name, c.line_user_id, c.id AS customer_id,
           p.name AS product_name,
           DATE_ADD(ps.sold_at, INTERVAL 2 MONTH) AS remind_date
    FROM product_sales ps
    JOIN customers c ON ps.customer_id = c.id
    JOIN products p ON ps.product_id = p.id
    WHERE DATE_ADD(ps.sold_at, INTERVAL 2 MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY remind_date ASC
    LIMIT 30
")->fetchAll();

// ── クーポン有効期限間近（30日以内） ──────────────────────
$expiringCoupons = $db->query("
    SELECT cp.*, c.name AS customer_name, c.line_user_id, c.id AS customer_id
    FROM coupons cp
    JOIN customers c ON cp.customer_id = c.id
    WHERE cp.used_at IS NULL
      AND cp.expired_at IS NOT NULL
      AND cp.expired_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
    ORDER BY cp.expired_at ASC
    LIMIT 30
")->fetchAll();

$pageTitle = 'ダッシュボード';
include __DIR__ . '/_header.php';
?>

<div class="page-title">ダッシュボード</div>

<?php if (!empty($stockAlerts)): ?>
<div class="alert alert-danger" style="display:flex;align-items:flex-start;gap:12px;">
    <div style="font-size:1.3em;">⚠️</div>
    <div>
        <strong style="display:block;margin-bottom:6px;">在庫アラート</strong>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach ($stockAlerts as $a): ?>
            <a href="<?= adminUrl('stock.php') ?>?product_id=<?= $a['id'] ?>" style="text-decoration:none;">
                <span style="background:#fff;border:1px solid #f5c6cb;padding:3px 12px;border-radius:12px;font-size:0.88em;color:#333;">
                    <?= $a['item_type']==='material'?'🔧 ':'' ?><?= h($a['name']) ?>
                    <span style="color:<?= $a['stock']<=0?'#dc3545':'#856404' ?>;font-weight:bold;"> 残<?= $a['stock'] ?><?= h($a['unit']) ?></span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 統計 -->
<div class="grid-3" style="margin-bottom:20px;">
    <a href="<?= adminUrl('reservations.php') ?>?status=pending" style="text-decoration:none;">
    <div class="stat-card" style="cursor:pointer;transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.15)'" onmouseout="this.style.boxShadow=''">
        <div class="stat-num" style="color:#e67e22;"><?= $pendingCount ?></div>
        <div class="stat-label">⏳ 未承認の仮予約</div>
    </div></a>
    <a href="<?= adminUrl('reservations.php') ?>?date=<?= $viewDate ?>" style="text-decoration:none;">
    <div class="stat-card" style="cursor:pointer;transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.15)'" onmouseout="this.style.boxShadow=''">
        <div class="stat-num"><?= $todayCount ?></div>
        <div class="stat-label">📅 本日の予約</div>
    </div></a>
    <a href="<?= adminUrl('customers.php') ?>" style="text-decoration:none;">
    <div class="stat-card" style="cursor:pointer;transition:box-shadow 0.2s;" onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.15)'" onmouseout="this.style.boxShadow=''">
        <div class="stat-num"><?= $customerCount ?></div>
        <div class="stat-label">👥 登録お客様数</div>
    </div></a>
</div>

<!-- 未承認予約 -->
<?php if (!empty($pendingList)): ?>
<div class="card">
    <div class="card-header">
        ⏳ 未承認の仮予約
        <a href="<?= adminUrl('reservations.php') ?>?status=pending" class="btn btn-sm btn-secondary">一覧へ</a>
    </div>
    <div class="card-body" style="padding:0;">
        <table>
            <tr><th>受付日時</th><th>お客様</th><th>希望日時</th><th>メニュー</th><th>担当</th><th>操作</th></tr>
            <?php foreach ($pendingList as $r): ?>
            <tr>
                <td><?= h(date('m/d H:i', strtotime($r['created_at']))) ?></td>
                <td><?= h($r['customer_name']) ?>様</td>
                <td><?php $dow=['日','月','火','水','木','金','土'][date('w',strtotime($r['start_at']))]; echo h(date('m/d（'.$dow.'） H:i',strtotime($r['start_at']))); ?>〜</td>
                <td><?= h($r['menu_name']) ?></td>
                <td><?= h($r['staff_name'] ?? '未定') ?></td>
                <td style="white-space:nowrap;">
                    <a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">詳細</a>
                    <form method="post" action="<?= adminUrl('reservations.php') ?>" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button class="btn btn-primary btn-sm">✅ 確定</button>
                    </form>
                    <?php if ($r['line_user_id']): ?>
                    <button class="btn btn-sm" style="background:#00B900;color:#fff;" onclick="openLineModal('<?= h($r['line_user_id']) ?>', '<?= h($r['customer_name']) ?>')">LINE</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="pc-gantt">
<!-- 本日タイムテーブル -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:6px;">
            <a href="<?= adminUrl('dashboard.php') ?>?date=<?= $prevDate ?>" class="btn btn-sm btn-secondary" style="padding:4px 10px;">◀ 前日</a>
            <button onclick="document.getElementById('calModal').style.display='flex'" class="btn btn-sm btn-secondary" style="padding:4px 12px;font-weight:bold;">
                📅 <?php
                    $dow = ['日','月','火','水','木','金','土'][date('w', strtotime($viewDate))];
                    echo date('m月d日（'.$dow.'）', strtotime($viewDate));
                    if ($viewDate === $today) echo ' <span style="color:#e74c3c;font-size:0.8em;">今日</span>';
                ?>
            </button>
            <a href="<?= adminUrl('dashboard.php') ?>?date=<?= $nextDate ?>" class="btn btn-sm btn-secondary" style="padding:4px 10px;">翌日 ▶</a>
            <?php if ($viewDate !== $today): ?>
            <a href="<?= adminUrl('dashboard.php') ?>" class="btn btn-sm" style="padding:4px 10px;background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9;">今日</a>
            <?php endif; ?>
        </div>
        <button onclick="openNewResvModal()" class="btn btn-sm btn-primary" style="margin-left:auto;">＋ 新規予約</button>
    </div>
    <div style="overflow-x:auto;padding:0;">
        <?php
        // 全スタッフ表示（予約ゼロでもグリッドを表示）
        $displayStaff = $staffListAll;
        if (!empty($timetable['none'])) $displayStaff[] = ['id'=>'none','name'=>'担当未定'];
        $statusBg  = ['pending'=>'#fff0c0','confirmed'=>'#c8f0d8','completed'=>'#e0e0e0'];
        $statusBdr = ['pending'=>'#f0a800','confirmed'=>'#4aaa70','completed'=>'#999'];
        $offTypeColors = ['holiday'=>'#ffe0e0','paid'=>'#dde8ff','training'=>'#fffacc','other'=>'#eedeff'];
        $offTypeLabels = ['holiday'=>'公休','paid'=>'有休','training'=>'研修','other'=>'休'];

        // 重複予約を複数行に振り分ける関数
        function assignRows(array $entries): array {
            $rows = [];
            foreach ($entries as $entry) {
                $startSlot = $entry['start_slot'];
                $endSlot   = $startSlot + $entry['span_slots'];
                $placed    = false;
                foreach ($rows as &$row) {
                    $conflict = false;
                    foreach ($row as $placed_entry) {
                        $ps = $placed_entry['start_slot'];
                        $pe = $ps + $placed_entry['span_slots'];
                        if ($startSlot < $pe && $endSlot > $ps) { $conflict = true; break; }
                    }
                    if (!$conflict) { $row[] = $entry; $placed = true; break; }
                }
                if (!$placed) $rows[] = [$entry];
            }
            return $rows;
        }
        ?>
        <div style="min-width:<?= 100 + $totalSlots * 38 ?>px;">
        <table style="border-collapse:collapse;width:100%;table-layout:fixed;">
            <thead><tr>
                <th style="width:110px;min-width:110px;background:#f8e8f0;color:#664;padding:10px 8px;font-size:0.85em;position:sticky;left:0;z-index:10;border-right:2px solid #e8c8d8;">スタイリスト</th>
                <?php for ($sl = 0; $sl < $totalSlots; $sl++):
                    $min = $shopOpen + $sl * $slotMin; $hh = (int)($min/60); $mm = $min%60;
                    $isHour = $mm===0; $isHalf = $mm===30;
                ?>
                <th style="width:42px;min-width:42px;background:<?= $isHour?'#f0e8f8':($isHalf?'#f5f0fc':'#faf8fe') ?>;color:<?= $isHour?'#664488':'#aaa' ?>;padding:5px 1px;font-size:0.72em;text-align:center;border-left:1px solid <?= $isHour?'#d8c8e8':'#ede8f5' ?>;">
                    <?= $isHour?sprintf('%d:%02d',$hh,$mm):($isHalf?'30':'') ?>
                </th>
                <?php endfor; ?>
            </tr></thead>
            <tbody id="timelineTbody">
            <?php if (empty($displayStaff)): ?>
            <tr><td colspan="<?= $totalSlots + 1 ?>" style="padding:22px 16px;color:#999;font-size:0.9em;background:#fff;">スタッフが登録されていません。「マスタ → スタッフ」から登録してください。</td></tr>
            <?php endif; ?>
            <?php foreach ($displayStaff as $staff):
                $sid     = $staff['id'];
                $isOff   = isset($todayOff[$sid]);
                $offType = $todayOff[$sid] ?? null;
                $rows    = assignRows($timetable[$sid] ?? []);
                if (empty($rows)) $rows = [[]];
                $rowCount = count($rows);
                foreach ($rows as $rowIdx => $rowEntries):
                    $occupied = [];
                    foreach ($rowEntries as $entry) $occupied[$entry['start_slot']] = $entry;
                    $rowBg = $isOff ? ($offTypeColors[$offType]??'#ffe0e0') : '#fff';
            ?>
            <tr style="height:68px;border-bottom:<?= $rowIdx===$rowCount-1?'2px solid #e8d8e8':'1px solid #f5eef5' ?>;" <?= $rowIdx===0 ? 'data-staff-row="'.$sid.'"' : '' ?>>
                <td style="position:sticky;left:0;z-index:5;background:<?= $rowBg ?>;padding:6px 8px;font-size:0.85em;font-weight:<?= $rowIdx===0?'bold':'normal' ?>;border-right:2px solid #e8c8d8;white-space:nowrap;color:<?= $isOff?'#c04040':($rowIdx===0?'#444':'#bbb') ?>;">
                    <?php if ($rowIdx===0): ?>
                    <div style="display:flex;align-items:center;gap:4px;">
                        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;"><?= h($staff['name']) ?><?php if ($isOff): ?><span style="font-size:0.75em;font-weight:normal;display:block;"><?= $offTypeLabels[$offType]??'休' ?></span><?php endif; ?></span>
                        <button type="button"
                            onclick="addExtraRow('<?= h(addslashes($sid)) ?>','<?= h(addslashes($staff['name'])) ?>')"
                            title="行を追加"
                            class="extra-row-btn"
                            style="flex-shrink:0;width:18px;height:18px;border-radius:50%;border:1px solid #bbb;background:#f5f5f5;color:#666;font-size:11px;line-height:1;cursor:pointer;padding:0;display:flex;align-items:center;justify-content:center;"
                            onmouseover="this.style.background='#6B9E8A';this.style.color='#fff';this.style.borderColor='#6B9E8A';"
                            onmouseout="this.style.background='#f5f5f5';this.style.color='#666';this.style.borderColor='#bbb';">＋</button>
                    </div>
                    <?php else: ?>　└<?php endif; ?>
                </td>
                <?php $sl = 0; while ($sl < $totalSlots):
                    if (isset($occupied[$sl])):
                        $entry = $occupied[$sl]; $r = $entry['reservation']; $span = $entry['span_slots'];
                        $bg  = $statusBg[$r['status']]  ?? '#e8f5f0';
                        $bdr = $statusBdr[$r['status']] ?? '#6B9E8A';
                        $tip = htmlspecialchars(date('H:i',strtotime($r['start_at'])).'〜'.date('H:i',strtotime($r['end_at']))."\n".($r['customer_name']??'お客様')."様\n".($r['menu_name']??''), ENT_QUOTES);
                ?>
                <td colspan="<?= $span ?>" style="padding:2px;border-left:1px solid #eee;vertical-align:middle;"
                    data-slot="<?= $sl ?>"
                    data-staff-id="<?= h($sid) ?>"
                    data-staff-name="<?= h($staff['name']) ?>"
                    data-time="<?= sprintf('%02d:%02d', (int)(($shopOpen+$sl*$slotMin)/60), ($shopOpen+$sl*$slotMin)%60) ?>"
                    ondragover="event.preventDefault();this.style.outline='2px dashed #6B9E8A';"
                    ondragleave="this.style.outline=''"
                    ondrop="onDrop(event,this)">
                    <a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $r['id'] ?>"
                       draggable="true"
                       data-id="<?= $r['id'] ?>"
                       data-customer="<?= h($r['customer_name']??'お客様') ?>"
                       data-menu="<?= h($r['menu_name']??'') ?>"
                       data-span="<?= $span ?>"
                       style="display:flex;flex-direction:column;justify-content:center;background:<?= $bg ?>;border-left:3px solid <?= $bdr ?>;border-radius:4px;padding:5px 7px;font-size:0.82em;color:#333;text-decoration:none;overflow:hidden;white-space:nowrap;height:58px;line-height:1.35;cursor:grab;"
                       onmouseover="showTip(event,'<?= $tip ?>')" onmouseout="hideTip()"
                       ondragstart="onDragStart(event)"
                       onclick="if(window._dragged){event.preventDefault();}">
                        <div style="font-weight:bold;overflow:hidden;text-overflow:ellipsis;"><?= h($r['customer_name']??'お客様') ?>様</div>
                        <div style="color:#666;overflow:hidden;text-overflow:ellipsis;"><?= h($r['menu_name']??'') ?></div>
                    </a>
                </td>
                <?php $sl += $span;
                    else:
                $min = $shopOpen + $sl * $slotMin; ?>
                <td style="border-left:1px solid <?= $min%60===0?'#ddd':'#f0f0f0' ?>;background:<?= $min%60===0?'#fafafa':'#fff' ?>;cursor:pointer;"
                    data-slot="<?= $sl ?>"
                    data-staff-id="<?= h($sid) ?>"
                    data-staff-name="<?= h($staff['name']) ?>"
                    data-time="<?= sprintf('%02d:%02d', (int)(($shopOpen+$sl*$slotMin)/60), ($shopOpen+$sl*$slotMin)%60) ?>"
                    ondragover="event.preventDefault();this.style.background='#e8f5f0';"
                    ondragleave="this.style.background=''"
                    ondrop="onDrop(event,this)"
                    onclick="openNewResvModalFromCell(this)"
                    onmouseover="this.style.background='#f0f7ff';"
                    onmouseout="this.style.background='<?= $min%60===0?'#fafafa':'#fff' ?>';"></td>
                <?php $sl++; endif; endwhile; ?>
            </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        </div>
        <div id="tip" style="display:none;position:fixed;background:#2c3e50;color:#fff;padding:8px 12px;border-radius:6px;font-size:0.82em;z-index:9999;pointer-events:none;white-space:pre-line;box-shadow:0 4px 12px rgba(0,0,0,0.3);"></div>
        <div style="padding:8px 16px;display:flex;gap:14px;font-size:0.8em;color:#888;border-top:1px solid #eee;">
            <span><span style="display:inline-block;width:10px;height:10px;background:#d4edda;border-left:3px solid #6B9E8A;margin-right:4px;"></span>確定</span>
            <span><span style="display:inline-block;width:10px;height:10px;background:#fff3cd;border-left:3px solid #ffc107;margin-right:4px;"></span>仮予約</span>
            <span><span style="display:inline-block;width:10px;height:10px;background:#e2e3e5;border-left:3px solid #adb5bd;margin-right:4px;"></span>完了</span>
        </div>
    </div>
</div>
</div><!-- /.pc-gantt -->

<!-- ═══ SP縦ガントチャート ═══ -->
<div class="card sp-gantt">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:6px;">
            <a href="<?= adminUrl('dashboard.php') ?>?date=<?= $prevDate ?>" class="btn btn-sm btn-secondary" style="padding:4px 10px;min-height:30px;">◀</a>
            <span style="font-weight:bold;font-size:0.88em;"><?php
                $spDow = ['日','月','火','水','木','金','土'][date('w', strtotime($viewDate))];
                echo date('m月d日（'.$spDow.'）', strtotime($viewDate));
                if ($viewDate === $today) echo ' <span style="color:#e74c3c;font-size:0.78em;margin-left:4px;">今日</span>';
            ?></span>
            <a href="<?= adminUrl('dashboard.php') ?>?date=<?= $nextDate ?>" class="btn btn-sm btn-secondary" style="padding:4px 10px;min-height:30px;">▶</a>
        </div>
        <button onclick="openNewResvModal()" class="btn btn-sm btn-primary" style="margin-left:auto;">＋ 新規</button>
    </div>
    <?php
    $spStatusBdr  = ['pending'=>'#f59e0b', 'confirmed'=>'#10b981', 'completed'=>'#9ca3af', 'cancelled'=>'#ef4444'];
    $spStatusBg   = ['pending'=>'#fef3c7', 'confirmed'=>'#d1fae5', 'completed'=>'#f3f4f6', 'cancelled'=>'#fee2e2'];
    $spStatusClr  = ['pending'=>'#92400e', 'confirmed'=>'#065f46', 'completed'=>'#374151', 'cancelled'=>'#991b1b'];
    $spStatusLabel= ['pending'=>'仮予約',    'confirmed'=>'確定',    'completed'=>'完了',    'cancelled'=>'キャンセル'];
    $spOffColors  = ['holiday'=>'#fff0f0','paid'=>'#eef2ff','training'=>'#fffdf0','other'=>'#f5f0ff'];
    $spOffLabels  = ['holiday'=>'公休','paid'=>'有休','training'=>'研修','other'=>'休'];
    if (empty($displayStaff)): ?>
    <div class="sp-gantt-empty" style="padding:20px 16px;">スタッフが登録されていません。「マスタ → スタッフ」から登録してください。</div>
    <?php endif;
    foreach ($displayStaff as $spStaff):
        $spSid    = $spStaff['id'];
        $spIsOff  = isset($todayOff[$spSid]);
        $spOffTyp = $todayOff[$spSid] ?? null;
        $spRows   = [];
        foreach (($timetable[$spSid] ?? []) as $ent) {
            $spRows[] = ['r' => $ent['reservation'], 'start' => $ent['start_slot'], 'span' => $ent['span_slots']];
        }
        usort($spRows, fn($a,$b) => $a['start'] <=> $b['start']);
        $spBg = $spIsOff ? ($spOffColors[$spOffTyp] ?? '#fff0f0') : '#f9fafb';
    ?>
    <div class="sp-gantt-staff" style="background:<?= $spBg ?>;">
        <div class="sp-gantt-staff-hd">
            <span class="sp-gantt-staff-name">
                <?= h($spStaff['name']) ?>
                <?php if ($spIsOff): ?>
                <span class="sp-gantt-off-badge"><?= $spOffLabels[$spOffTyp] ?? '休' ?></span>
                <?php endif; ?>
            </span>
            <button onclick="openNewResvModal('<?= $viewDate ?>', '', '<?= h($spSid) ?>')"
                class="btn btn-sm" style="background:var(--accent);color:#fff;padding:3px 10px;font-size:0.76em;min-height:28px;">＋</button>
        </div>
        <?php if (empty($spRows)): ?>
        <div class="sp-gantt-empty">予約なし</div>
        <?php else: ?>
        <?php foreach ($spRows as $spRow):
            $spR        = $spRow['r'];
            $spStartMin = $shopOpen + $spRow['start'] * $slotMin;
            $spEndMin   = $spStartMin + $spRow['span'] * $slotMin;
            $spStartStr = sprintf('%02d:%02d', intdiv($spStartMin, 60), $spStartMin % 60);
            $spEndStr   = sprintf('%02d:%02d', intdiv($spEndMin, 60), $spEndMin % 60);
            $spStat     = $spR['status'] ?? 'pending';
        ?>
        <a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $spR['id'] ?>"
            class="sp-gantt-item"
            style="border-left-color:<?= $spStatusBdr[$spStat] ?? '#6B9E8A' ?>;">
            <div class="sp-gantt-time"><?= $spStartStr ?>〜<?= $spEndStr ?></div>
            <div class="sp-gantt-info">
                <div class="sp-gantt-customer"><?= h($spR['customer_name'] ?? 'お客様') ?>様</div>
                <div class="sp-gantt-menu"><?= h($spR['menu_name'] ?? '') ?></div>
            </div>
            <span class="sp-gantt-badge"
                style="background:<?= $spStatusBg[$spStat]??'#f3f4f6' ?>;color:<?= $spStatusClr[$spStat]??'#374151' ?>;">
                <?= $spStatusLabel[$spStat] ?? '' ?>
            </span>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div><!-- /.sp-gantt -->

<!-- ═══ 誕生日間近 ═══ -->
<?php
$bdayPrevMonth = $bdayMonth - 1; $bdayPrevYear = $bdayYear;
if ($bdayPrevMonth < 1) { $bdayPrevMonth = 12; $bdayPrevYear--; }
$bdayNextMonth = $bdayMonth + 1; $bdayNextYear = $bdayYear;
if ($bdayNextMonth > 12) { $bdayNextMonth = 1; $bdayNextYear++; }
$bdayMonthLabel = $bdayYear . '年' . $bdayMonth . '月';
$bdayBase = adminUrl('dashboard.php') . '?';
$bdayParamsBase = 'bdy='.$bdayYear.'&bdm='.$bdayMonth;
$csvUrl = adminUrl('dashboard.php') . '?action=birthday_csv&bdy='.$bdayYear.'&bdm='.$bdayMonth;
?>
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span>🎂 誕生日間近のお客様</span>
        <div style="display:flex;align-items:center;gap:6px;margin-left:4px;">
            <a href="<?= $bdayBase ?>bdy=<?= $bdayPrevYear ?>&bdm=<?= $bdayPrevMonth ?>" class="btn btn-sm btn-secondary" style="padding:3px 8px;">◀ 前月</a>
            <span style="font-weight:bold;font-size:0.95em;"><?= $bdayMonthLabel ?></span>
            <a href="<?= $bdayBase ?>bdy=<?= $bdayNextYear ?>&bdm=<?= $bdayNextMonth ?>" class="btn btn-sm btn-secondary" style="padding:3px 8px;">翌月 ▶</a>
        </div>
        <a href="<?= $csvUrl ?>" class="btn btn-sm" style="margin-left:auto;background:#217346;color:#fff;padding:3px 12px;">⬇ CSV</a>
    </div>
    <div style="padding:0;">
        <?php if (empty($birthdayList)): ?>
        <p style="padding:20px;color:#888;text-align:center;">該当するお客様はいません</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:0.88em;">
            <thead><tr style="background:#fdf6ff;">
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #eee;">お名前</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #eee;">誕生日</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #eee;">あと〇日</th>
                <th style="padding:8px 10px;text-align:left;border-bottom:1px solid #eee;">〒 住所</th>
                <th style="padding:8px 10px;text-align:center;border-bottom:1px solid #eee;">LINE</th>
                <th style="padding:8px 10px;text-align:center;border-bottom:1px solid #eee;">詳細</th>
            </tr></thead>
            <tbody>
            <?php foreach ($birthdayList as $c): $d = (int)$c['days_until']; ?>
            <tr style="border-bottom:1px solid #f5f5f5;">
                <td style="padding:8px 10px;font-weight:bold;"><?= h($c['name']) ?>様</td>
                <td style="padding:8px 10px;"><?= h(date('m月d日', strtotime($c['birthdate']))) ?></td>
                <td style="padding:8px 10px;">
                    <?php if ($d === 0): ?>
                    <span style="color:#e74c3c;font-weight:bold;">今日🎂🎉</span>
                    <?php elseif ($d > 0): ?>
                    <span style="color:#e67e22;">あと<?= $d ?>日</span>
                    <?php else: ?>
                    <span style="color:#aaa;"><?= abs($d) ?>日前</span>
                    <?php endif; ?>
                </td>
                <td style="padding:8px 10px;color:#555;font-size:0.9em;"><?= $c['address'] ? '〒 '.h($c['address']) : '<span style="color:#ccc;">-</span>' ?></td>
                <td style="padding:8px 10px;text-align:center;">
                    <?php if ($c['line_user_id']): ?>
                    <button class="btn btn-sm" style="background:#00B900;color:#fff;padding:3px 10px;"
                        onclick="openLineModal(this.dataset.uid,this.dataset.name,'birthday')"
                        data-uid="<?= h($c['line_user_id']) ?>"
                        data-name="<?= h($c['name']) ?>">LINE</button>
                    <?php else: ?><span style="color:#ddd;">-</span><?php endif; ?>
                </td>
                <td style="padding:8px 10px;text-align:center;">
                    <a href="<?= adminUrl('customers.php') ?>?id=<?= $c['id'] ?>" class="btn btn-sm btn-secondary" style="padding:3px 10px;">詳細</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <!-- ページネーション -->
        <?php if ($bdayTotalPages > 1): ?>
        <div style="padding:12px 16px;display:flex;align-items:center;gap:6px;border-top:1px solid #f0f0f0;flex-wrap:wrap;">
            <span style="color:#888;font-size:0.85em;">全<?= $bdayTotalCount ?>件</span>
            <?php for ($p = 1; $p <= $bdayTotalPages; $p++): ?>
            <a href="<?= $bdayBase . $bdayParamsBase ?>&bdp=<?= $p ?>"
               style="padding:3px 10px;border-radius:4px;border:1px solid <?= $p===$bdayPage?'#6B9E8A':'#ddd' ?>;background:<?= $p===$bdayPage?'#6B9E8A':'#fff' ?>;color:<?= $p===$bdayPage?'#fff':'#555' ?>;text-decoration:none;font-size:0.85em;"><?= $p ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ 物販リマインド ＋ クーポン期限 ═══ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

<!-- 左：物販リマインド -->
<div class="card" style="margin:0;">
    <div class="card-header">🛍️ 購入後2ヶ月リマインド</div>
    <div style="padding:0;">
        <?php if (empty($remindList)): ?>
        <p style="padding:20px;color:#888;text-align:center;font-size:0.9em;">該当なし</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:0.85em;">
            <thead><tr style="background:#f5fbf8;">
                <th style="padding:7px 10px;text-align:left;border-bottom:1px solid #eee;">お名前</th>
                <th style="padding:7px 10px;text-align:left;border-bottom:1px solid #eee;">購入日</th>
                <th style="padding:7px 10px;text-align:left;border-bottom:1px solid #eee;">商品名</th>
                <th style="padding:7px 10px;text-align:center;border-bottom:1px solid #eee;">LINE</th>
                <th style="padding:7px 10px;text-align:center;border-bottom:1px solid #eee;">詳細</th>
            </tr></thead>
            <tbody>
            <?php foreach ($remindList as $rm): ?>
            <tr style="border-bottom:1px solid #f5f5f5;">
                <td style="padding:7px 10px;font-weight:bold;"><?= h($rm['customer_name']) ?>様</td>
                <td style="padding:7px 10px;white-space:nowrap;color:#555;"><?= h(date('m/d', strtotime($rm['sold_at']))) ?></td>
                <td style="padding:7px 10px;color:#444;"><?= h($rm['product_name']) ?></td>
                <td style="padding:7px 10px;text-align:center;">
                    <?php if ($rm['line_user_id']): ?>
                    <button class="btn btn-sm" style="background:#00B900;color:#fff;padding:2px 8px;font-size:0.8em;"
                        onclick="openLineModal(this.dataset.uid,this.dataset.name)"
                        data-uid="<?= h($rm['line_user_id']) ?>"
                        data-name="<?= h($rm['customer_name']) ?>">LINE</button>
                    <?php else: ?><span style="color:#ddd;">-</span><?php endif; ?>
                </td>
                <td style="padding:7px 10px;text-align:center;">
                    <a href="<?= adminUrl('customers.php') ?>?id=<?= $rm['customer_id'] ?>" class="btn btn-sm btn-secondary" style="padding:2px 8px;font-size:0.8em;">詳細</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- 右：クーポン期限間近 -->
<div class="card" style="margin:0;">
    <div class="card-header">🎫 クーポン有効期限間近</div>
    <div style="padding:0;">
        <?php if (empty($expiringCoupons)): ?>
        <p style="padding:20px;color:#888;text-align:center;font-size:0.9em;">該当なし</p>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:0.85em;">
            <thead><tr style="background:#fffbf0;">
                <th style="padding:7px 10px;text-align:left;border-bottom:1px solid #eee;">お名前</th>
                <th style="padding:7px 10px;text-align:left;border-bottom:1px solid #eee;">クーポン名</th>
                <th style="padding:7px 10px;text-align:left;border-bottom:1px solid #eee;">期限</th>
                <th style="padding:7px 10px;text-align:center;border-bottom:1px solid #eee;">LINE</th>
                <th style="padding:7px 10px;text-align:center;border-bottom:1px solid #eee;">詳細</th>
            </tr></thead>
            <tbody>
            <?php foreach ($expiringCoupons as $cp):
                $daysLeft = (int)ceil((strtotime($cp['expired_at']) - time()) / 86400);
            ?>
            <tr style="border-bottom:1px solid #f5f5f5;">
                <td style="padding:7px 10px;font-weight:bold;"><?= h($cp['customer_name']) ?>様</td>
                <td style="padding:7px 10px;color:#444;"><?= h($cp['description']) ?></td>
                <td style="padding:7px 10px;white-space:nowrap;">
                    <span style="color:<?= $daysLeft<=3?'#e74c3c':($daysLeft<=7?'#e67e22':'#555') ?>;font-weight:<?= $daysLeft<=7?'bold':'normal' ?>;">
                        <?= h(date('m/d', strtotime($cp['expired_at']))) ?>
                        <span style="font-size:0.85em;">(あと<?= $daysLeft ?>日)</span>
                    </span>
                </td>
                <td style="padding:7px 10px;text-align:center;">
                    <?php if ($cp['line_user_id']): ?>
                    <button class="btn btn-sm" style="background:#00B900;color:#fff;padding:2px 8px;font-size:0.8em;"
                        onclick="openLineModal(this.dataset.uid,this.dataset.name,'coupon',this.dataset.code,this.dataset.desc,<?= (int)$cp['discount'] ?>,this.dataset.expiry)"
                        data-uid="<?= h($cp['line_user_id']) ?>"
                        data-name="<?= h($cp['customer_name']) ?>"
                        data-code="<?= h($cp['code']) ?>"
                        data-desc="<?= h($cp['description']) ?>"
                        data-expiry="<?= h(date('m月d日', strtotime($cp['expired_at']))) ?>">LINE</button>
                    <?php else: ?><span style="color:#ddd;">-</span><?php endif; ?>
                </td>
                <td style="padding:7px 10px;text-align:center;">
                    <a href="<?= adminUrl('customers.php') ?>?id=<?= $cp['customer_id'] ?>" class="btn btn-sm btn-secondary" style="padding:2px 8px;font-size:0.8em;">詳細</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

</div><!-- /grid -->

<!-- カレンダーモーダル -->
<div id="calModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9998;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:12px;padding:24px;width:320px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <button onclick="calPrevMonth()" class="btn btn-sm btn-secondary">◀</button>
            <strong id="calTitle" style="font-size:1em;"></strong>
            <button onclick="calNextMonth()" class="btn btn-sm btn-secondary">▶</button>
        </div>
        <div id="calGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;text-align:center;font-size:0.85em;"></div>
        <div style="margin-top:14px;text-align:right;">
            <button onclick="document.getElementById('calModal').style.display='none'" class="btn btn-sm btn-secondary">閉じる</button>
        </div>
    </div>
</div>

<!-- 新規予約モーダル -->
<div id="newResvModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9997;align-items:center;justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:12px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="padding:16px 20px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
            <strong>＋ 新規予約追加</strong>
            <button onclick="document.getElementById('newResvModal').style.display='none'" style="border:none;background:none;font-size:1.4em;cursor:pointer;color:#888;">✕</button>
        </div>
        <form method="post" style="padding:20px;">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="dashboard_add_reservation">
            <input type="hidden" name="customer_id" id="nrCustomerId" value="">

            <!-- お客様検索 -->
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-weight:600;">お客様 <span style="color:#e74c3c;">*</span></label>
                <div style="position:relative;">
                    <input type="text" id="nrCustomerSearch" placeholder="名前を入力してサジェスト..." autocomplete="off"
                           style="width:100%;padding:8px 10px 8px 34px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;font-size:0.95em;"
                           oninput="searchCustomers(this.value)"
                           onfocus="if(this.value.length>0)searchCustomers(this.value)">
                    <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#aaa;font-size:0.9em;pointer-events:none;">🔍</span>
                    <div id="nrCustomerResults" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);border:1px solid #ddd;border-radius:8px;max-height:200px;overflow-y:auto;background:#fff;box-shadow:0 6px 20px rgba(0,0,0,0.12);z-index:100;"></div>
                </div>
                <div id="nrCustomerSelected" style="display:none;margin-top:6px;padding:8px 12px;background:#e8f5e9;border-radius:6px;font-size:0.9em;color:#2e7d32;justify-content:space-between;align-items:center;">
                    <span id="nrCustomerSelectedName"></span>
                    <button type="button" onclick="clearCustomer()" style="border:none;background:none;color:#888;cursor:pointer;font-size:1.1em;">✕</button>
                </div>
            </div>

            <!-- 日付・時間 -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                <div class="form-group" style="margin:0;">
                    <label style="font-weight:600;">日付 <span style="color:#e74c3c;">*</span></label>
                    <input type="date" name="date" id="nrDate" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin:0;">
                    <label style="font-weight:600;">開始時間 <span style="color:#e74c3c;">*</span></label>
                    <input type="time" name="time" id="nrTime" required step="900" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
                </div>
            </div>

            <!-- メニュー -->
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-weight:600;">メニュー <span style="color:#e74c3c;">*</span></label>
                <select name="menu_id" id="nrMenu" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
                    <option value="">-- 選択してください --</option>
                    <?php foreach ($menus as $m): ?>
                    <option value="<?= $m['id'] ?>" data-duration="<?= (int)$m['duration_min'] ?>"><?= h($m['name']) ?>（<?= $m['duration_min'] ?>分）</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 担当スタイリスト -->
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-weight:600;">担当スタイリスト</label>
                <select name="staff_id" id="nrStaff" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
                    <option value="">指名なし</option>
                    <?php foreach ($staffAll as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- 備考 -->
            <div class="form-group" style="margin-bottom:20px;">
                <label>備考</label>
                <input type="text" name="note" placeholder="任意" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('newResvModal').style.display='none'" class="btn btn-secondary">キャンセル</button>
                <button type="submit" class="btn btn-primary" onclick="return validateNewResv()">✅ 予約を追加（確定）</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── カレンダーモーダル ───────────────────────────────
const _viewDate = '<?= $viewDate ?>';

// ── 自動更新：LINE等での新規予約・変更を検知して自動リロード ──
(function () {
    let currentSig = null;
    const POLL_MS = 5000;

    function isBusy() {
        // モーダル表示中・入力中はリロードで邪魔しない
        const modals = ['calModal', 'newResvModal'];
        for (const id of modals) {
            const el = document.getElementById(id);
            if (el && getComputedStyle(el).display !== 'none') return true;
        }
        const active = document.activeElement;
        if (active && /^(INPUT|TEXTAREA|SELECT)$/.test(active.tagName)) return true;
        return false;
    }

    async function poll() {
        try {
            const res = await fetch(`<?= adminUrl('api/dashboard_check.php') ?>?date=${encodeURIComponent(_viewDate)}`, { cache: 'no-store' });
            const d = await res.json();
            if (!d.success) return;
            if (currentSig === null) { currentSig = d.sig; return; }
            if (d.sig !== currentSig) {
                if (isBusy()) return; // 次回ポーリングで再チェック
                location.reload();
            }
        } catch (e) { /* 通信エラーは無視して次回再試行 */ }
    }
    setInterval(poll, POLL_MS);
})();
const SHOP_CLOSE_TIME = '<?php
    try { echo h(substr($db->query("SELECT close_time FROM shop_settings WHERE id=1")->fetchColumn() ?: "19:00:00", 0, 5)); }
    catch (Throwable $e) { echo "19:00"; }
?>';
let calYear  = parseInt(_viewDate.split('-')[0]);
let calMonth = parseInt(_viewDate.split('-')[1]);

function renderCal() {
    const months = ['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];
    document.getElementById('calTitle').textContent = calYear + '年 ' + months[calMonth-1];
    const grid = document.getElementById('calGrid');
    const days = ['日','月','火','水','木','金','土'];
    grid.innerHTML = '';
    days.forEach(d => {
        const h = document.createElement('div');
        h.textContent = d;
        h.style.cssText = 'font-weight:bold;color:#888;padding:4px 0;font-size:0.8em;';
        if (d==='日') h.style.color='#e74c3c';
        if (d==='土') h.style.color='#3498db';
        grid.appendChild(h);
    });
    const first = new Date(calYear, calMonth-1, 1).getDay();
    const last  = new Date(calYear, calMonth, 0).getDate();
    const today = '<?= $today ?>';
    for (let i=0; i<first; i++) { const e=document.createElement('div'); grid.appendChild(e); }
    for (let d=1; d<=last; d++) {
        const ds = calYear + '-' + String(calMonth).padStart(2,'0') + '-' + String(d).padStart(2,'0');
        const el = document.createElement('div');
        el.textContent = d;
        const dow = new Date(calYear, calMonth-1, d).getDay();
        el.style.cssText = 'padding:6px 2px;border-radius:50%;cursor:pointer;transition:background 0.15s;';
        if (dow===0) el.style.color='#e74c3c';
        if (dow===6) el.style.color='#3498db';
        if (ds===_viewDate) { el.style.background='#6B9E8A'; el.style.color='#fff'; el.style.fontWeight='bold'; }
        else if (ds===today) { el.style.background='#fff3cd'; el.style.fontWeight='bold'; }
        el.onmouseover = () => { if (ds!==_viewDate) el.style.background='#f0f0f0'; };
        el.onmouseout  = () => { if (ds!==_viewDate && ds!==today) el.style.background=''; else if(ds===today) el.style.background='#fff3cd'; };
        el.onclick = () => { location.href = '<?= adminUrl('dashboard.php') ?>?date=' + ds; };
        grid.appendChild(el);
    }
}
function calPrevMonth() { calMonth--; if(calMonth<1){calMonth=12;calYear--;} renderCal(); }
function calNextMonth() { calMonth++; if(calMonth>12){calMonth=1;calYear++;} renderCal(); }
renderCal();

// ── 新規予約モーダル ─────────────────────────────────
function openNewResvModal(date, time, staffId) {
    document.getElementById('nrDate').value  = date  || _viewDate;
    document.getElementById('nrTime').value  = time  || '10:00';
    if (staffId && staffId !== 'none') {
        const sel = document.getElementById('nrStaff');
        for (let i=0; i<sel.options.length; i++) {
            if (sel.options[i].value == staffId) { sel.selectedIndex=i; break; }
        }
    } else {
        document.getElementById('nrStaff').selectedIndex = 0;
    }
    clearCustomer();
    document.getElementById('nrCustomerSearch').value = '';
    document.getElementById('nrCustomerResults').style.display = 'none';
    _suggestIndex = -1;
    document.getElementById('newResvModal').style.display = 'flex';
    setTimeout(()=>document.getElementById('nrCustomerSearch').focus(), 100);
}

function openNewResvModalFromCell(cell) {
    if (window._dragged) return;
    openNewResvModal(_viewDate, cell.dataset.time, cell.dataset.staffId);
}

// ── お客様サジェスト ──────────────────────────────────
let _searchTimer = null;
let _suggestData = [];
let _suggestIndex = -1;

const _searchInput   = () => document.getElementById('nrCustomerSearch');
const _searchResults = () => document.getElementById('nrCustomerResults');

function searchCustomers(q) {
    clearTimeout(_searchTimer);
    _suggestIndex = -1;
    const box = _searchResults();
    if (!q || q.length < 1) { box.style.display='none'; _suggestData=[]; return; }

    // 入力中は「検索中...」を即座に出す
    box.innerHTML = '<div style="padding:10px 12px;color:#aaa;font-size:0.88em;">検索中...</div>';
    box.style.display = 'block';

    _searchTimer = setTimeout(() => {
        fetch('<?= adminUrl('customers.php') ?>?ajax=search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                _suggestData = data;
                renderSuggest();
            })
            .catch(() => { box.style.display='none'; });
    }, 220);
}

function renderSuggest() {
    const box = _searchResults();
    if (!_suggestData.length) {
        box.innerHTML = '<div style="padding:10px 12px;color:#aaa;text-align:center;font-size:0.88em;">該当なし</div>';
        box.style.display = 'block';
        return;
    }
    box.innerHTML = _suggestData.map((c, i) =>
        `<div class="sg-item" data-idx="${i}"
              style="padding:9px 14px;cursor:pointer;border-bottom:1px solid #f5f5f5;font-size:0.92em;display:flex;align-items:center;gap:8px;${i===_suggestIndex?'background:#e8f4ff;':''}"
              onmousedown="event.preventDefault();selectCustomer(${c.id},'${c.name.replace(/'/g,"\\'")}')"
              onmouseover="highlightSuggest(${i})"
              onmouseout="unhighlightSuggest(${i})">
            <span style="width:24px;height:24px;border-radius:50%;background:#e8f0fe;color:#4a6fa5;font-size:0.78em;display:flex;align-items:center;justify-content:center;font-weight:bold;flex-shrink:0;">${c.name.charAt(0)}</span>
            <span>${c.name}<span style="color:#aaa;">様</span></span>
        </div>`
    ).join('');
    box.style.display = 'block';
}

function highlightSuggest(i) {
    _suggestIndex = i;
    document.querySelectorAll('#nrCustomerResults .sg-item').forEach((el, idx) => {
        el.style.background = idx===i ? '#e8f4ff' : '';
    });
}
function unhighlightSuggest(i) {
    if (_suggestIndex === i) {
        document.querySelectorAll('#nrCustomerResults .sg-item')[i].style.background = '';
    }
}

// キーボード操作
document.addEventListener('keydown', function(e) {
    const box = _searchResults();
    if (box.style.display === 'none' || !_suggestData.length) return;
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        _suggestIndex = Math.min(_suggestIndex+1, _suggestData.length-1);
        renderSuggest();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        _suggestIndex = Math.max(_suggestIndex-1, 0);
        renderSuggest();
    } else if (e.key === 'Enter' && _suggestIndex >= 0) {
        e.preventDefault();
        const c = _suggestData[_suggestIndex];
        selectCustomer(c.id, c.name);
    } else if (e.key === 'Escape') {
        box.style.display = 'none';
    }
});

// 外クリックで閉じる
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('nrCustomerSearch').closest('div');
    if (wrap && !wrap.contains(e.target)) {
        _searchResults().style.display = 'none';
    }
});

function selectCustomer(id, name) {
    document.getElementById('nrCustomerId').value = id;
    _searchInput().value = '';
    _searchResults().style.display = 'none';
    _suggestData = []; _suggestIndex = -1;
    document.getElementById('nrCustomerSelectedName').textContent = name + '様';
    document.getElementById('nrCustomerSelected').style.display = 'flex';
}

function clearCustomer() {
    document.getElementById('nrCustomerId').value = '';
    document.getElementById('nrCustomerSelected').style.display = 'none';
}

function validateNewResv() {
    if (!document.getElementById('nrCustomerId').value) {
        alert('お客様を選択してください'); return false;
    }
    const menuSel = document.getElementById('nrMenu');
    if (!menuSel.value) {
        alert('メニューを選択してください'); return false;
    }
    // 閉店時間オーバーフローの確認
    const time = document.getElementById('nrTime').value;
    const dur  = parseInt(menuSel.selectedOptions[0]?.dataset.duration || 0);
    if (time && dur && typeof SHOP_CLOSE_TIME !== 'undefined') {
        const [h, mi]  = time.split(':').map(Number);
        const endMin   = h * 60 + mi + dur;
        const [ch, cm] = SHOP_CLOSE_TIME.split(':').map(Number);
        if (endMin > ch * 60 + cm) {
            const endStr = String(Math.floor(endMin / 60)).padStart(2, '0') + ':' + String(endMin % 60).padStart(2, '0');
            if (!confirm('⚠️ 施術終了が ' + endStr + ' となり、閉店時間（' + SHOP_CLOSE_TIME + '）を過ぎます。\nこのまま予約を登録しますか？')) {
                return false;
            }
        }
    }
    return true;
}

// ── ＋ボタン：追加行の挿入 ────────────────────────────
const _extraRows = {}; // staffId => trElement

function addExtraRow(staffId, staffName) {
    // すでに追加済みなら削除（トグル）
    if (_extraRows[staffId]) {
        _extraRows[staffId].remove();
        delete _extraRows[staffId];
        const btn = document.querySelector(`tr[data-staff-row="${staffId}"] .extra-row-btn`);
        if (btn) { btn.style.background='#f5f5f5'; btn.style.color='#666'; btn.style.borderColor='#bbb'; }
        return;
    }

    // タイムラインtbodyを特定（id="timelineTbody"）
    const tbody = document.getElementById('timelineTbody');
    if (!tbody) return;

    const allRows = Array.from(tbody.querySelectorAll('tr'));
    let lastRow = null;
    allRows.forEach(tr => {
        if (tr.dataset.staffRow === String(staffId)) lastRow = tr;
    });
    if (!lastRow) return;

    // 直後の子行（data-staff-row なし）も飛ばして末尾へ
    let cur = lastRow.nextElementSibling;
    while (cur && !cur.dataset.staffRow && !cur.dataset.extraRow) {
        lastRow = cur;
        cur = cur.nextElementSibling;
    }

    const totalSlots = <?= $totalSlots ?>;
    const shopOpen   = <?= $shopOpen ?>;
    const slotMin    = <?= $slotMin ?>;

    const tr = document.createElement('tr');
    tr.style.cssText = 'border-bottom:2px solid #6B9E8A;background:#f0fbf6;';
    tr.dataset.extraRow = staffId;

    // スタイリスト名セル
    const nameTd = document.createElement('td');
    nameTd.style.cssText = 'position:sticky;left:0;z-index:5;background:#e8f8f0;padding:6px 8px;font-size:0.78em;border-right:2px solid #e8c8d8;white-space:nowrap;color:#4aaa70;';
    nameTd.innerHTML = `<div style="display:flex;align-items:center;gap:4px;">
        <span style="flex:1;">　└ 追加枠</span>
        <button type="button" onclick="removeExtraRow('${staffId}')"
            style="width:16px;height:16px;border-radius:50%;border:1px solid #ccc;background:#fff;color:#999;font-size:10px;cursor:pointer;padding:0;display:flex;align-items:center;justify-content:center;"
            onmouseover="this.style.background='#ffeded';this.style.color='#e74c3c';"
            onmouseout="this.style.background='#fff';this.style.color='#999';">✕</button>
    </div>`;
    tr.appendChild(nameTd);

    // 時間スロットセル
    for (let sl = 0; sl < totalSlots; sl++) {
        const totalMin = shopOpen + sl * slotMin;
        const hh = Math.floor(totalMin / 60);
        const mm = totalMin % 60;
        const timeStr = String(hh).padStart(2,'0') + ':' + String(mm).padStart(2,'0');
        const isHour  = mm === 0;

        const td = document.createElement('td');
        td.style.cssText = `border-left:1px solid ${isHour?'#ddd':'#f0f0f0'};background:${isHour?'#f5fbf8':'#f9fef9'};cursor:pointer;`;
        td.dataset.slot      = sl;
        td.dataset.staffId   = staffId;
        td.dataset.staffName = staffName;
        td.dataset.time      = timeStr;

        td.addEventListener('dragover', e => { e.preventDefault(); td.style.background='#c8f0d8'; });
        td.addEventListener('dragleave', ()=> { td.style.background = isHour?'#f5fbf8':'#f9fef9'; });
        td.addEventListener('drop', e => onDrop(e, td));
        td.addEventListener('click', () => { if(!window._dragged) openNewResvModal(_viewDate, timeStr, staffId); });
        td.addEventListener('mouseover', () => { td.style.background='#d4edda'; });
        td.addEventListener('mouseout',  () => { td.style.background = isHour?'#f5fbf8':'#f9fef9'; });

        tr.appendChild(td);
    }

    lastRow.after(tr);
    _extraRows[staffId] = tr;

    // ＋ボタンをアクティブ表示
    const btn = document.querySelector(`tr[data-staff-row="${staffId}"] .extra-row-btn`);
    if (btn) { btn.style.background='#6B9E8A'; btn.style.color='#fff'; btn.style.borderColor='#6B9E8A'; }
}

function removeExtraRow(staffId) {
    if (_extraRows[staffId]) {
        _extraRows[staffId].remove();
        delete _extraRows[staffId];
        const btn = document.querySelector(`tr[data-staff-row="${staffId}"] .extra-row-btn`);
        if (btn) { btn.style.background='#f5f5f5'; btn.style.color='#666'; btn.style.borderColor='#bbb'; }
    }
}

function showTooltip(e, text) {
    const tt = document.getElementById('tt');
    tt.textContent = text;
    tt.style.display = 'block';
    tt.style.left = (e.clientX + 12) + 'px';
    tt.style.top  = (e.clientY - 10) + 'px';
}
function hideTooltip() {
    document.getElementById('tt').style.display = 'none';
}
document.addEventListener('mousemove', function(e) {
    const tt = document.getElementById('tt');
    if (tt.style.display === 'block') {
        tt.style.left = (e.clientX + 12) + 'px';
        tt.style.top  = (e.clientY - 10) + 'px';
    }
});
// ドラッグ&ドロップ
let _dragData = null;
window._dragged = false;

function onDragStart(e) {
    const el = e.currentTarget;
    _dragData = {
        id:       el.dataset.id,
        customer: el.dataset.customer,
        menu:     el.dataset.menu,
        span:     parseInt(el.dataset.span),
    };
    window._dragged = false;
    e.dataTransfer.effectAllowed = 'move';
    hideTip();
}

// ドラッグ終了時（成功・キャンセル問わず）に必ずフラグリセット
document.addEventListener('dragend', function() {
    // 少し遅らせてonclick後にリセット
    setTimeout(() => { window._dragged = false; }, 50);
});

function onDrop(e, cell) {
    e.preventDefault();
    cell.style.background = '';
    cell.style.outline = '';
    if (!_dragData) return;

    const staffId   = cell.dataset.staffId;
    const staffName = cell.dataset.staffName;
    const time      = cell.dataset.time;

    if (!staffId || !time) { _dragData = null; return; }

    const [hh, mm] = time.split(':').map(Number);
    const startMin = hh * 60 + mm;
    const endMin   = startMin + _dragData.span * 15;
    const endTime  = String(Math.floor(endMin/60)).padStart(2,'0') + ':' + String(endMin%60).padStart(2,'0');

    const msg = `【予約変更の確認】\n\n` +
        `${_dragData.customer}様（${_dragData.menu}）\n\n` +
        `担当：${staffName}\n` +
        `時間：${time}〜${endTime}\n\n` +
        `この内容で変更しますか？`;

    window._dragged = true; // confirm前にセット（onclickを抑制）

    if (!confirm(msg)) {
        window._dragged = false; // キャンセル時はリセット
        _dragData = null;
        return;
    }

    const newStart = _viewDate + ' ' + time + ':00';
    const newEnd   = _viewDate + ' ' + endTime + ':00';

    fetch('<?= adminUrl('update_reservation_slot.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            id: _dragData.id,
            staff_id: staffId === 'none' ? null : staffId,
            start_at: newStart,
            end_at:   newEnd,
            csrf_token: '<?= csrf() ?>'
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            window._dragged = false;
            alert('更新に失敗しました：' + (data.error || ''));
        }
    });
    _dragData = null;
}

function showTip(e, text) {
    const t = document.getElementById('tip');
    t.textContent = text; t.style.display = 'block';
    t.style.left = (e.clientX+14)+'px'; t.style.top = (e.clientY-10)+'px';
}
function hideTip() { document.getElementById('tip').style.display='none'; }
document.addEventListener('mousemove',function(e){
    const t=document.getElementById('tip');
    if(t&&t.style.display==='block'){t.style.left=(e.clientX+14)+'px';t.style.top=(e.clientY-10)+'px';}
});
// LINE関連は _footer.php の共通モーダル（openLineModal等）を使用
let currentLineUserId = ''; // 後方互換のため残す
</script>

<?php include __DIR__ . '/_footer.php'; ?>
