<?php
// ============================================================
// lib/availability.php  - 空き枠確認
// 営業時間: 10:00〜19:00 / 30分刻み / 定休日: 火曜
// ============================================================

require_once __DIR__ . '/../config/db.php';

define('OPEN_HOUR',  10);  // フォールバック値（実際はshop_settingsから取得）
define('CLOSE_HOUR', 19);
define('SLOT_MIN',   30);

/**
 * 営業時間（shop_settingsから取得、リクエスト内キャッシュ）
 */
function shopHours(): array
{
    static $h = null;
    if ($h === null) {
        $h = ['open' => sprintf('%02d:00', OPEN_HOUR), 'close' => sprintf('%02d:00', CLOSE_HOUR)];
        try {
            $row = db()->query('SELECT open_time, close_time FROM shop_settings WHERE id = 1')->fetch();
            if (!empty($row['open_time']))  $h['open']  = substr($row['open_time'], 0, 5);
            if (!empty($row['close_time'])) $h['close'] = substr($row['close_time'], 0, 5);
        } catch (Throwable $e) {}
    }
    return $h;
}

function shopOpenMin(): int  { [$hh,$mm] = explode(':', shopHours()['open']);  return (int)$hh * 60 + (int)$mm; }
function shopCloseMin(): int { [$hh,$mm] = explode(':', shopHours()['close']); return (int)$hh * 60 + (int)$mm; }

/**
 * 指定日・スタッフの空き時間スロットを返す（30分刻み）
 */
function getAvailableSlots(string $date, int $staffId, int $durationMin = 60, int $excludeId = 0): array
{
    if (isRegularHoliday($date) || isShopHoliday($date)) return []; // 定休日・臨時休業

    $db     = db();
    $sql    = 'SELECT start_at, end_at FROM reservations
        WHERE staff_id = ? AND DATE(start_at) = ?
          AND status IN ("pending","confirmed")';
    $params = [$staffId, $date];
    if ($excludeId) { $sql .= ' AND id <> ?'; $params[] = $excludeId; } // 予約変更時：自分の予約は除外
    $sql .= ' ORDER BY start_at';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $booked = $stmt->fetchAll();

    $blocked  = [];
    foreach ($booked as $b) {
        $startMin = (int)((strtotime($b['start_at']) - strtotime($date)) / 60);
        $endMin   = (int)((strtotime($b['end_at'])   - strtotime($date)) / 60);
        for ($m = $startMin; $m < $endMin; $m += SLOT_MIN) {
            $blocked[$m] = true;
        }
    }

    $slots     = [];
    $openMin   = shopOpenMin();
    $closeMin  = shopCloseMin();
    $lastStart = $closeMin - $durationMin;

    for ($m = $openMin; $m <= $lastStart; $m += SLOT_MIN) {
        $ok = true;
        for ($i = 0; $i < $durationMin; $i += SLOT_MIN) {
            if (isset($blocked[$m + $i])) { $ok = false; break; }
        }
        if ($ok) $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }

    return $slots;
}

/**
 * 指定日の全スタッフ共通の空き時間スロット（最短メニュー30分基準）
 * いずれかのスタッフが対応できる時間帯を返す
 */
function isRegularHoliday(string $date): bool
{
    try {
        $db  = db();
        $row = $db->query("SELECT regular_holidays FROM shop_settings WHERE id=1")->fetch();
        if ($row && $row['regular_holidays'] !== null) {
            $holidays = array_filter(explode(',', $row['regular_holidays']));
            return in_array((string)date('w', strtotime($date)), $holidays);
        }
    } catch (Throwable $e) {}
    // フォールバック：火曜
    return date('w', strtotime($date)) == 2;
}

function isShopHoliday(string $date): bool
{
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM shop_holidays WHERE holiday_date=?");
        $stmt->execute([$date]);
        return $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {}
    return false;
}

function getDateAvailableSlots(string $date, int $durationMin = 30, int $excludeId = 0): array
{
    if (isRegularHoliday($date) || isShopHoliday($date)) return [];

    $db       = db();
    $maxConcurrent = 3; // 同時間帯の上限

    // 当日休みでないスタッフのみ
    $stmt = $db->prepare("
        SELECT s.id FROM staff s
        WHERE s.is_active = 1
          AND NOT EXISTS (
              SELECT 1 FROM staff_days_off d WHERE d.staff_id=s.id AND d.off_date=?
          )
    ");
    $stmt->execute([$date]);
    $staffIds = array_column($stmt->fetchAll(), 'id');

    if (empty($staffIds)) return [];

    $allSlots = [];
    foreach ($staffIds as $sid) {
        $slots = getAvailableSlots($date, (int)$sid, $durationMin, $excludeId);
        foreach ($slots as $s) {
            $allSlots[$s] = ($allSlots[$s] ?? 0) + 1;
        }
    }

    // 同時間帯上限チェック：既存予約が上限以上なら除外
    $result = [];
    foreach ($allSlots as $slot => $availCount) {
        // その時間帯の既存予約数をカウント
        [$h, $m] = explode(':', $slot);
        $slotStart = $date . ' ' . $slot . ':00';
        $slotEnd   = date('Y-m-d H:i:s', strtotime($slotStart) + 30 * 60);
        $sqlExist = "SELECT COUNT(*) FROM reservations
            WHERE DATE(start_at) = ?
              AND status NOT IN ('cancelled')
              AND start_at < ? AND end_at > ?";
        $existParams = [$date, $slotEnd, $slotStart];
        if ($excludeId) { $sqlExist .= ' AND id <> ?'; $existParams[] = $excludeId; }
        $existStmt = $db->prepare($sqlExist);
        $existStmt->execute($existParams);
        $existCount = (int)$existStmt->fetchColumn();

        if ($existCount < $maxConcurrent) {
            $result[] = $slot;
        }
    }

    sort($result);
    return $result;
}

/**
 * 指定日・時間帯に空きがあるスタッフを返す
 */
function getAvailableStaffAt(string $date, string $time, int $durationMin = 60, int $excludeId = 0): array
{
    $db    = db();
    // 当日休みでないスタッフのみ
    $stmt  = $db->prepare("
        SELECT s.id, s.name, s.photo_url, s.can_cut, s.can_color, s.can_perm, s.can_treatment
        FROM staff s
        WHERE s.is_active = 1
          AND NOT EXISTS (
              SELECT 1 FROM staff_days_off d WHERE d.staff_id=s.id AND d.off_date=?
          )
        ORDER BY s.display_order
    ");
    $stmt->execute([$date]);
    $staffList = $stmt->fetchAll();

    $result = [];
    foreach ($staffList as $s) {
        $slots = getAvailableSlots($date, (int)$s['id'], $durationMin, $excludeId);
        if (in_array($time, $slots)) {
            $result[] = $s;
        }
    }
    return $result;
}

/**
 * メニュー名からスタッフ対応カラムを判定
 */
function menuToColumns(string $menuName): array
{
    if (str_contains($menuName, 'カット＋カラー') || str_contains($menuName, 'カット+カラー')) {
        return ['can_cut', 'can_color']; // 両方必要
    }
    if (str_contains($menuName, 'カット'))        return ['can_cut'];
    if (str_contains($menuName, 'カラー'))        return ['can_color'];
    if (str_contains($menuName, 'パーマ'))        return ['can_perm'];
    if (str_contains($menuName, 'トリートメント')) return ['can_treatment'];
    return [];
}

/**
 * スタッフが対応できるメニュー一覧を返す
 */
function getStaffMenus(int $staffId): array
{
    $db   = db();
    $stmt = $db->prepare('SELECT can_cut, can_color, can_perm, can_treatment FROM staff WHERE id = ?');
    $stmt->execute([$staffId]);
    $s = $stmt->fetch();
    if (!$s) return [];

    $where = [];
    if ($s['can_cut'])       $where[] = "name LIKE '%カット%'";
    if ($s['can_color'])     $where[] = "name LIKE '%カラー%'";
    if ($s['can_perm'])      $where[] = "name LIKE '%パーマ%'";
    if ($s['can_treatment']) $where[] = "name LIKE '%トリートメント%'";

    // カット＋カラーは両方できる場合のみ
    if ($s['can_cut'] && $s['can_color']) {
        // 既にLIKEで両方含まれるが、複合メニューは明示的に含める（重複除去はDB側）
    }

    if (empty($where)) return [];

    $sql  = 'SELECT id, name, duration_min, price FROM menus WHERE is_active = 1 AND (' . implode(' OR ', $where) . ') ORDER BY display_order';
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

/**
 * メニュー対応スタッフ一覧
 */
function getEligibleStaff(string $menuName): array
{
    $db   = db();
    $cols = menuToColumns($menuName);

    if (empty($cols)) {
        $stmt = $db->query('SELECT id, name, photo_url FROM staff WHERE is_active = 1 ORDER BY display_order');
        return $stmt->fetchAll();
    }

    if (count($cols) === 2) {
        $stmt = $db->query('SELECT id, name, photo_url FROM staff WHERE is_active = 1 AND can_cut = 1 AND can_color = 1 ORDER BY display_order');
    } else {
        $col  = $cols[0];
        $stmt = $db->prepare("SELECT id, name, photo_url FROM staff WHERE is_active = 1 AND {$col} = 1 ORDER BY display_order");
        $stmt->execute();
    }
    return $stmt->fetchAll();
}

/**
 * スタッフ一覧をメニュー対応可否で絞り込む
 * （can_* カラムを含むスタッフ配列を渡すこと）
 */
function filterStaffByMenu(array $staffList, string $menuName): array
{
    $cols = menuToColumns($menuName);
    if (empty($cols)) return $staffList;
    return array_values(array_filter($staffList, function ($s) use ($cols) {
        foreach ($cols as $c) {
            if (empty($s[$c])) return false;
        }
        return true;
    }));
}

/**
 * 予約フロー用メニュー一覧（カテゴリ付き）
 */
function getBookingMenus(): array
{
    return db()->query('
        SELECT id, category, name, duration_min, price
        FROM menus
        WHERE is_active = 1
        ORDER BY display_order
    ')->fetchAll();
}

/**
 * 顧客の利用可能クーポン（未使用・期限内）
 */
function getCustomerCoupons(int $customerId): array
{
    $stmt = db()->prepare('
        SELECT id, code, description, discount_type, discount, discount_rate, expired_at
        FROM coupons
        WHERE customer_id = ?
          AND used_at IS NULL
          AND (expired_at IS NULL OR expired_at >= NOW())
        ORDER BY expired_at IS NULL, expired_at
    ');
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}

/**
 * 直近N日間で空きのある日付を返す
 */
function getAvailableDates(int $days = 60, int $durationMin = 30): array
{
    $db     = db();
    $result = [];
    $totalStaff = (int)$db->query('SELECT COUNT(*) FROM staff WHERE is_active=1')->fetchColumn();

    for ($i = 1; $i <= $days; $i++) {
        $date = date('Y-m-d', strtotime("+{$i} days"));

        // 定休日・臨時休業日チェック
        if (isRegularHoliday($date) || isShopHoliday($date)) continue;

        // 全スタッフ休日チェック（全員休みなら予約不可）
        $offCount = (int)$db->prepare("SELECT COUNT(*) FROM staff_days_off WHERE off_date=?")->execute([$date]) ? 0 : 0;
        $stmt = $db->prepare("SELECT COUNT(*) FROM staff_days_off sd JOIN staff s ON sd.staff_id=s.id WHERE sd.off_date=? AND s.is_active=1");
        $stmt->execute([$date]);
        $offCount = (int)$stmt->fetchColumn();
        if ($totalStaff > 0 && $offCount >= $totalStaff) continue;

        $slots = getDateAvailableSlots($date, $durationMin);
        if (!empty($slots)) $result[$date] = true;
    }
    return $result;
}
