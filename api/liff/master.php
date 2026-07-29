<?php
// ============================================================
// api/liff/master.php - 予約画面初期データ
// GET ?line_uid=Uxxxx
// 返却: menus(カテゴリ順), staff, customer(登録状態), coupons, shop
// ============================================================
require_once __DIR__ . '/_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_err(405, '許可されていないメソッドです');

$lineUid = $_GET['line_uid'] ?? '';
$db = db();

// メニュー
$menus = $db->query('
    SELECT id, category, name, duration_min, price
    FROM menus WHERE is_active = 1 ORDER BY display_order
')->fetchAll();

// スタッフ
$staff = $db->query('
    SELECT id, name, photo_url, can_cut, can_color, can_perm, can_treatment
    FROM staff WHERE is_active = 1 ORDER BY display_order
')->fetchAll();

// 店舗名
try {
    $shopName = $db->query('SELECT shop_name FROM shop_settings WHERE id=1')->fetchColumn() ?: 'HAPTIC';
} catch (Throwable $e) {
    $shopName = 'HAPTIC';
}

// 予約行をLIFF用に整形
function liffReservationRow(array $r): array
{
    $snap     = json_decode($r['menu_snapshot'] ?? '', true) ?: [];
    $duration = $snap['duration_min'] ?? ($r['menu_duration'] ?? null);
    $price    = $snap['price'] ?? ($r['menu_price'] ?? null);
    $start    = strtotime($r['start_at']);
    return [
        'id'       => (int)$r['id'],
        'date'     => date('Y-m-d', $start),
        'time'     => date('H:i', $start),
        'end_time' => $duration !== null ? date('H:i', $start + (int)$duration * 60) : null,
        'status'   => $r['status'],
        'menu'     => $snap['menu'] ?? ($r['menu_name'] ?? ''),  // 複数メニューは結合ラベル（スナップショット）優先
        'staff'    => $r['staff_name'] ?? ($snap['staff'] ?? '指名なし'),
        'duration' => $duration !== null ? (int)$duration : null,
        'price'    => $price !== null ? (int)$price : null,
        'coupon'   => $snap['coupon_desc'] ?? null,
        'menu_id'  => $r['menu_id'] !== null ? (int)$r['menu_id'] : null,
        'menu_ids' => $snap['menu_ids'] ?? ($r['menu_id'] !== null ? [(int)$r['menu_id']] : []),
        'staff_id' => $r['staff_id'] !== null ? (int)$r['staff_id'] : null,
    ];
}

// 顧客・クーポン
$customer = null;
$coupons  = [];
$reservations = ['upcoming' => [], 'history' => []];
if ($lineUid) {
    $stmt = $db->prepare('SELECT id, name, line_name, phone, gender, birthdate FROM customers WHERE line_user_id = ?');
    $stmt->execute([$lineUid]);
    $c = $stmt->fetch();
    if ($c) {
        $customer = [
            'registered' => !empty($c['name']),
            'name'       => $c['name'] ?? '',
            'line_name'  => $c['line_name'] ?? '',
            'phone'      => $c['phone'] ?? '',
        ];
        foreach (getCustomerCoupons((int)$c['id']) as $cp) {
            $coupons[] = [
                'id'            => (int)$cp['id'],
                'code'          => $cp['code'],
                'description'   => $cp['description'],
                'discount_type' => $cp['discount_type'],
                'discount'      => (int)$cp['discount'],
                'discount_rate' => $cp['discount_rate'] !== null ? (int)$cp['discount_rate'] : null,
                'expired_at'    => $cp['expired_at'] ? date('Y-m-d', strtotime($cp['expired_at'])) : null,
            ];
        }

        // 今後のご予約（仮予約・確定）
        $stmt = $db->prepare("
            SELECT r.id, r.start_at, r.status, r.menu_snapshot, r.menu_id, r.staff_id,
                   m.name AS menu_name, m.duration_min AS menu_duration, m.price AS menu_price,
                   s.name AS staff_name
            FROM reservations r
            LEFT JOIN menus m ON r.menu_id = m.id
            LEFT JOIN staff s ON r.staff_id = s.id
            WHERE r.customer_id = ?
              AND r.status IN ('pending','confirmed')
              AND r.end_at >= NOW()
            ORDER BY r.start_at
        ");
        $stmt->execute([$c['id']]);
        foreach ($stmt->fetchAll() as $r) {
            $reservations['upcoming'][] = liffReservationRow($r);
        }

        // 過去の来店履歴（直近10件）
        $stmt = $db->prepare("
            SELECT r.id, r.start_at, r.status, r.menu_snapshot, r.menu_id, r.staff_id,
                   m.name AS menu_name, m.duration_min AS menu_duration, m.price AS menu_price,
                   s.name AS staff_name
            FROM reservations r
            LEFT JOIN menus m ON r.menu_id = m.id
            LEFT JOIN staff s ON r.staff_id = s.id
            WHERE r.customer_id = ?
              AND (r.status = 'completed' OR (r.status = 'confirmed' AND r.end_at < NOW()))
            ORDER BY r.start_at DESC
            LIMIT 10
        ");
        $stmt->execute([$c['id']]);
        foreach ($stmt->fetchAll() as $r) {
            $reservations['history'][] = liffReservationRow($r);
        }
    }
}

json_ok([
    'shop'         => ['name' => $shopName],
    'menus'        => $menus,
    'staff'        => $staff,
    'customer'     => $customer,
    'coupons'      => $coupons,
    'reservations' => $reservations,
]);
