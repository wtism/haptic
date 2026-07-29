<?php
// admin/staff_schedule.php  - スタッフ休日カレンダー管理
require_once __DIR__ . '/auth.php';
requireLogin();

$db  = db();
$msg = '';

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // 休日トグル（あれば削除、なければ追加）
    if ($action === 'toggle_off') {
        $staffId = (int)$_POST['staff_id'];
        $date    = $_POST['date'];
        $offType = $_POST['off_type'] ?? 'holiday';

        $stmt = $db->prepare('SELECT id FROM staff_days_off WHERE staff_id=? AND off_date=?');
        $stmt->execute([$staffId, $date]);
        $existing = $stmt->fetch();

        if ($offType === 'delete' || ($existing && !$offType)) {
            // 解除
            if ($existing) $db->prepare('DELETE FROM staff_days_off WHERE id=?')->execute([$existing['id']]);
        } elseif ($existing) {
            // 種別更新
            $db->prepare('UPDATE staff_days_off SET off_type=? WHERE id=?')->execute([$offType, $existing['id']]);
        } else {
            // 新規登録
            $db->prepare('INSERT INTO staff_days_off (staff_id, off_date, off_type) VALUES (?,?,?)')->execute([$staffId, $date, $offType]);
        }
        header('Location: ' . adminUrl('staff_schedule.php') . "?year={$year}&month={$month}");
        exit;
    }

    // 月一括設定（全スタッフの定休日を一括登録など）
    if ($action === 'bulk_off') {
        $staffId = (int)$_POST['staff_id'];
        $dates   = $_POST['dates'] ?? [];
        $offType = $_POST['off_type'] ?? 'holiday';

        // 月の既存休日を削除して再登録
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate));
        $db->prepare('DELETE FROM staff_days_off WHERE staff_id=? AND off_date BETWEEN ? AND ?')->execute([$staffId, $startDate, $endDate]);

        foreach ($dates as $date) {
            if (strtotime($date)) {
                $db->prepare('INSERT IGNORE INTO staff_days_off (staff_id, off_date, off_type) VALUES (?,?,?)')->execute([$staffId, $date, $offType]);
            }
        }
        header('Location: ' . adminUrl('staff_schedule.php') . "?year={$year}&month={$month}&msg=updated");
        exit;
    }
}

if (($_GET['msg'] ?? '') === 'updated') $msg = '休日を更新しました';

// スタッフ一覧（有効なスタッフのみ）
$staffList = $db->query('SELECT * FROM staff WHERE is_active=1 ORDER BY display_order')->fetchAll();

// 月の休日データ取得
$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));
$daysInMonth = (int)date('t', strtotime($startDate));
$firstDow    = (int)date('w', strtotime($startDate));

$offStmt = $db->prepare('SELECT * FROM staff_days_off WHERE off_date BETWEEN ? AND ? ORDER BY staff_id, off_date');
$offStmt->execute([$startDate, $endDate]);
$allOff = $offStmt->fetchAll();

// 店舗定休日取得
try {
    $shopRow = $db->query('SELECT regular_holidays FROM shop_settings WHERE id=1')->fetch();
    $regularHolidays = array_filter(explode(',', $shopRow['regular_holidays'] ?? '2'));
} catch (Throwable $e) {
    $regularHolidays = ['2']; // フォールバック：火曜
}

// 臨時休業日
$shopHolidaysStmt = $db->prepare('SELECT holiday_date, reason FROM shop_holidays WHERE holiday_date BETWEEN ? AND ?');
$shopHolidaysStmt->execute([$startDate, $endDate]);
$shopHolidays = [];
foreach ($shopHolidaysStmt->fetchAll() as $h) $shopHolidays[$h['holiday_date']] = $h['reason'];

// スタッフ別・日付別インデックス
$offIndex = [];
foreach ($allOff as $o) {
    $offIndex[$o['staff_id']][$o['off_date']] = $o['off_type'];
}

$offTypeLabels = ['holiday'=>'公休','paid'=>'有休','training'=>'研修','other'=>'その他'];
$offTypeColors = ['holiday'=>'#ffd6d6','paid'=>'#d6e8ff','training'=>'#fffad6','other'=>'#e8d6ff'];

$prevY = $month==1 ? $year-1 : $year; $prevM = $month==1 ? 12 : $month-1;
$nextY = $month==12 ? $year+1 : $year; $nextM = $month==12 ? 1 : $month+1;

$pageTitle = 'スタッフ休日カレンダー';
include __DIR__ . '/_header.php';
?>

<div class="page-title">スタッフ休日カレンダー</div>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

<!-- 月ナビ -->
<div class="card">
    <div class="card-body" style="padding:12px 20px;display:flex;align-items:center;gap:16px;">
        <a href="?year=<?= $prevY ?>&month=<?= $prevM ?>" class="btn btn-sm btn-secondary">◀ 前月</a>
        <span style="font-size:1.2em;font-weight:bold;"><?= $year ?>年<?= $month ?>月</span>
        <a href="?year=<?= $nextY ?>&month=<?= $nextM ?>" class="btn btn-sm btn-secondary">翌月 ▶</a>
        <a href="?year=<?= date('Y') ?>&month=<?= date('m') ?>" class="btn btn-sm" style="background:#eee;color:#333;">今月</a>
        <div style="margin-left:auto;font-size:0.85em;color:#888;">
            セルをクリックで公休をON/OFF
        </div>
    </div>
</div>

<!-- 凡例 -->
<div style="display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap;font-size:0.85em;">
    <?php foreach ($offTypeLabels as $k=>$v): ?>
    <span style="display:flex;align-items:center;gap:5px;">
        <span style="width:16px;height:16px;background:<?= $offTypeColors[$k] ?>;border-radius:3px;display:inline-block;border:1px solid #ddd;"></span><?= $v ?>
    </span>
    <?php endforeach; ?>
    <span style="display:flex;align-items:center;gap:5px;">
        <span style="width:16px;height:16px;background:#f0f0f0;border-radius:3px;display:inline-block;border:1px solid #ddd;"></span>定休日（火）
    </span>
</div>

<!-- カレンダーグリッド（スタッフ×日付） -->
<div style="overflow-x:auto;">
<table style="border-collapse:collapse;min-width:<?= 120 + $daysInMonth * 42 ?>px;">
    <thead>
        <tr>
            <th style="width:120px;min-width:120px;background:#2c3e50;color:#fff;padding:10px;position:sticky;left:0;z-index:10;font-size:0.88em;">スタッフ</th>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
                $ts  = mktime(0,0,0,$month,$d,$year);
                $dow = (int)date('w', $ts);
                $dowLabels = ['日','月','火','水','木','金','土'];
                $isToday   = date('Y-m-d', $ts) === date('Y-m-d');
                $isShopOff = in_array((string)$dow, $regularHolidays) || isset($shopHolidays[date('Y-m-d',$ts)]);
                $headBg = $dow===0?'#ffe8e8':($dow===6?'#e8eeff':($isShopOff?'#f0f0f0':'#2c3e50'));
                $headColor = $dow===0||$dow===6||$isShopOff?'#555':'#fff';
            ?>
            <th style="width:42px;min-width:42px;background:<?= $headBg ?>;color:<?= $headColor ?>;padding:6px 2px;text-align:center;font-size:0.78em;border-left:1px solid #444;<?= $isToday?'outline:2px solid #f39c12;':''; ?>">
                <div><?= $d ?></div>
                <div style="font-size:0.85em;"><?= $dowLabels[$dow] ?></div>
            </th>
            <?php endfor; ?>
            <th style="background:#2c3e50;color:#fff;padding:6px;font-size:0.82em;white-space:nowrap;">休日数</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($staffList as $s):
        $staffOffDays = $offIndex[$s['id']] ?? [];
        $offCount = count($staffOffDays);
    ?>
    <tr>
        <td style="position:sticky;left:0;z-index:5;background:#fff;padding:8px 10px;font-weight:bold;font-size:0.85em;border-right:2px solid #eee;border-bottom:1px solid #eee;white-space:nowrap;">
            <?= h($s['name']) ?>
            <?php if (!empty($s['role_name'])): ?><div style="font-size:0.75em;color:#888;font-weight:normal;"><?= h($s['role_name']) ?></div><?php endif; ?>
        </td>
        <?php for ($d = 1; $d <= $daysInMonth; $d++):
            $ts      = mktime(0,0,0,$month,$d,$year);
            $dateStr = date('Y-m-d', $ts);
            $dow     = (int)date('w', $ts);
            $isShopOff = $dow === 2;
            $isPast    = $ts < mktime(0,0,0);
            $offType   = $staffOffDays[$dateStr] ?? null;
            $isOff     = $offType !== null;

            $isShopRegular = in_array((string)$dow, $regularHolidays);
            $isShopTmp     = isset($shopHolidays[$dateStr]);
            $isShopOff     = $isShopRegular || $isShopTmp;

            if ($isShopOff) {
                $cellBg    = '#f0f0f0';
                $cellTitle = $isShopTmp ? ('臨休:'.($shopHolidays[$dateStr]??'')) : '定休';
                $clickable = false;
            } elseif ($isOff) {
                $cellBg = $offTypeColors[$offType] ?? '#ffd6d6';
                $cellTitle = $offTypeLabels[$offType] ?? '休';
                $clickable = true;
            } else {
                $cellBg = $isPast ? '#fafafa' : '#fff';
                $cellTitle = '';
                $clickable = true;
            }
        ?>
        <td style="border:1px solid #eee;background:<?= $cellBg ?>;text-align:center;padding:0;width:42px;height:40px;vertical-align:middle;<?= date('Y-m-d',$ts)===date('Y-m-d')?'outline:2px solid #f39c12;':''; ?>">
                <?php if ($isShopOff): ?>
            <span style="font-size:0.75em;color:#999;" title="<?= h($shopHolidays[$dateStr] ?? '定休日') ?>">休</span>
            <?php elseif ($clickable): ?>
            <button type="button"
                style="width:100%;height:40px;border:none;background:transparent;cursor:pointer;font-size:0.82em;color:<?= $isOff?'#c0392b':'#ccc' ?>;"
                onclick="openOffModal(<?= $s['id'] ?>,'<?= h($s['name']) ?>','<?= $dateStr ?>','<?= date('m/d',strtotime($dateStr)) ?>（<?= ['日','月','火','水','木','金','土'][$dow] ?>）','<?= $offType ?? '' ?>')"
                title="<?= $isOff ? $cellTitle.'（クリックで変更・解除）' : 'クリックで休日登録' ?>">
                <?= $isOff ? $cellTitle : '' ?>
            </button>
            <?php endif; ?>
        </td>
        <?php endfor; ?>
        <td style="text-align:center;font-weight:bold;color:<?= $offCount>0?'#e74c3c':'#888' ?>;border-bottom:1px solid #eee;padding:6px;">
            <?= $offCount ?>日
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- 月次サマリー -->
<div class="card" style="margin-top:20px;">
    <div class="card-header">月次休日サマリー</div>
    <div class="card-body">
        <div style="display:flex;gap:20px;flex-wrap:wrap;">
            <?php foreach ($staffList as $s):
                $offDays = $offIndex[$s['id']] ?? [];
                $byType  = array_count_values($offDays);
            ?>
            <div style="background:#f8f9fa;border-radius:8px;padding:12px 16px;min-width:140px;">
                <div style="font-weight:bold;margin-bottom:6px;"><?= h($s['name']) ?></div>
                <?php if (empty($offDays)): ?>
                <div style="color:#aaa;font-size:0.85em;">休日なし</div>
                <?php else: ?>
                <?php foreach ($byType as $type => $cnt): ?>
                <div style="font-size:0.85em;display:flex;align-items:center;gap:6px;margin-bottom:2px;">
                    <span style="width:10px;height:10px;background:<?= $offTypeColors[$type] ?? '#eee' ?>;border-radius:2px;display:inline-block;"></span>
                    <?= h($offTypeLabels[$type] ?? $type) ?>：<?= $cnt ?>日
                </div>
                <?php endforeach; ?>
                <div style="font-size:0.85em;color:#6B9E8A;font-weight:bold;margin-top:4px;">合計：<?= count($offDays) ?>日</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 休日登録モーダル -->
<div id="offModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;width:320px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="padding:14px 18px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
            <strong id="offModalTitle">休日登録</strong>
            <button onclick="closeOffModal()" style="border:none;background:none;font-size:1.4em;cursor:pointer;color:#888;">✕</button>
        </div>
        <div style="padding:18px;">
            <form method="post" id="offModalForm">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="toggle_off">
                <input type="hidden" name="staff_id" id="offStaffId">
                <input type="hidden" name="date" id="offDate">

                <div style="margin-bottom:14px;font-size:0.9em;color:#555;" id="offModalSub"></div>

                <div id="offTypeSection">
                    <label style="font-size:0.88em;font-weight:bold;margin-bottom:8px;display:block;">種別を選択</label>
                    <?php foreach ($offTypeLabels as $k=>$v): ?>
                    <label style="display:flex;align-items:center;gap:8px;padding:8px;border-radius:6px;cursor:pointer;margin-bottom:4px;border:1px solid #eee;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='#fff'">
                        <input type="radio" name="off_type" value="<?= $k ?>" <?= $k==='holiday'?'checked':'' ?>>
                        <span style="width:12px;height:12px;border-radius:3px;background:<?= $offTypeColors[$k] ?>;display:inline-block;border:1px solid #ddd;"></span>
                        <?= $v ?>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div style="display:flex;gap:10px;margin-top:16px;">
                    <button type="submit" class="btn btn-primary" style="flex:1;">登録</button>
                    <button type="button" id="offDeleteBtn" class="btn btn-danger" style="display:none;" onclick="deleteOff()">解除</button>
                    <button type="button" class="btn btn-secondary" onclick="closeOffModal()">キャンセル</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 解除用フォーム -->
<form method="post" id="offDeleteForm" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
    <input type="hidden" name="action" value="toggle_off">
    <input type="hidden" name="staff_id" id="delStaffId">
    <input type="hidden" name="date" id="delDate">
    <input type="hidden" name="off_type" value="delete">
</form>

<script>
function openOffModal(staffId, staffName, date, dateLabel, currentType) {
    document.getElementById('offStaffId').value = staffId;
    document.getElementById('delStaffId').value = staffId;
    document.getElementById('offDate').value    = date;
    document.getElementById('delDate').value    = date;
    document.getElementById('offModalTitle').textContent = staffName + ' - ' + dateLabel;
    document.getElementById('offModalSub').textContent   = currentType ? '現在：' + {'holiday':'公休','paid':'有休','training':'研修','other':'その他'}[currentType] : '休日を登録します';

    // 現在の種別を選択
    if (currentType) {
        const radio = document.querySelector('input[name="off_type"][value="'+currentType+'"]');
        if (radio) radio.checked = true;
    } else {
        document.querySelector('input[name="off_type"][value="holiday"]').checked = true;
    }

    // 解除ボタン表示
    document.getElementById('offDeleteBtn').style.display = currentType ? '' : 'none';

    const m = document.getElementById('offModal');
    m.style.display = 'flex'; m.style.alignItems = 'center'; m.style.justifyContent = 'center';
}
function closeOffModal() { document.getElementById('offModal').style.display = 'none'; }
function deleteOff() {
    if (!confirm('休日登録を解除しますか？')) return;
    document.getElementById('offDeleteForm').submit();
}
document.getElementById('offModal').addEventListener('click', function(e) { if (e.target===this) closeOffModal(); });
</script>

<?php include __DIR__ . '/_footer.php'; ?>
