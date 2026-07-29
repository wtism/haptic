<?php
// ============================================================
// api/liff/slots.php - 空き情報
// GET ?mode=dates&duration=60&days=45          → 空きのある日付一覧
// GET ?mode=times&date=2026-07-22&duration=60  → その日の空き時間
// GET ?mode=staff&date=..&time=10:00&duration=60&menu=カット → 空き＆対応スタッフ
// ============================================================
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err(405, '許可されていないメソッドです');

$mode     = $_GET['mode'] ?? 'dates';
$duration = max(15, min(360, (int)($_GET['duration'] ?? 30)));
$exclude  = max(0, (int)($_GET['exclude'] ?? 0)); // 予約変更時：自分の予約IDを空き判定から除外

switch ($mode) {
    case 'dates':
        $days  = max(1, min(60, (int)($_GET['days'] ?? 45)));
        $dates = array_keys(getAvailableDates($days, $duration));
        json_ok(['dates' => $dates]);
        // no break (json_ok exits)

    case 'week':
        // 指定開始日から7日分の空き時間をまとめて返す（HPB風マトリクス用）
        $start = $_GET['start'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) json_err(400, '日付の形式が不正です');
        $days   = [];
        $closed = [];
        $todayStr = date('Y-m-d');
        $nowTime  = date('H:i');
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($start . " +{$i} days"));
            // 定休日・臨時休業は列ごと非表示にするため区別して返す
            if (isRegularHoliday($d) || isShopHoliday($d)) {
                $closed[] = $d;
                $days[$d] = [];
                continue;
            }
            if ($d < $todayStr) { $days[$d] = []; continue; }
            $times = getDateAvailableSlots($d, $duration, $exclude);
            if ($d === $todayStr) {
                $times = array_values(array_filter($times, fn($t) => $t > $nowTime));
            }
            $days[$d] = $times;
        }
        $hours = shopHours();
        json_ok(['days' => $days, 'closed' => $closed, 'open' => $hours['open'], 'close' => $hours['close']]);

    case 'times':
        $date = $_GET['date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_err(400, '日付の形式が不正です');
        if (strtotime($date) < strtotime('today')) json_err(400, '過去の日付は指定できません');
        $times = getDateAvailableSlots($date, $duration, $exclude);
        // 当日は現在時刻より後の枠のみ
        if ($date === date('Y-m-d')) {
            $now = date('H:i');
            $times = array_values(array_filter($times, fn($t) => $t > $now));
        }
        json_ok(['times' => $times]);

    case 'staff':
        $date = $_GET['date'] ?? '';
        $time = $_GET['time'] ?? '';
        $menu = $_GET['menu'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_err(400, '日付の形式が不正です');
        if (!preg_match('/^\d{2}:\d{2}$/', $time))       json_err(400, '時間の形式が不正です');
        $staff = getAvailableStaffAt($date, $time, $duration, $exclude);
        // 複数メニューは「|」区切り。全メニューに対応できるスタッフのみ残す
        foreach (array_filter(explode('|', $menu)) as $mn) {
            $staff = filterStaffByMenu($staff, $mn);
        }
        $result = array_map(fn($s) => [
            'id'        => (int)$s['id'],
            'name'      => $s['name'],
            'photo_url' => $s['photo_url'],
        ], $staff);
        json_ok(['staff' => array_values($result)]);

    default:
        json_err(400, '不明なmodeです');
}
