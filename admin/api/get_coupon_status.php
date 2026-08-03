<?php
/**
 * GET /admin/api/get_coupon_status.php?customer_id=1&reservation_id=1
 * 直近で使用済みになったクーポンを返す（5秒以内に使用済みになったもの）
 */
require_once dirname(__DIR__) . '/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$customerId    = (int)($_GET['customer_id'] ?? 0);
$reservationId = (int)($_GET['reservation_id'] ?? 0);

if (!$customerId) {
    echo json_encode(['coupon' => null]);
    exit;
}

$db = db();

// 直近10秒以内に使用済みになった・かつまだレジに紐付いていないクーポンを取得
$stmt = $db->prepare('
    SELECT id, code, description, discount, discount_type, discount_rate
    FROM coupons
    WHERE customer_id = ?
      AND used_at IS NOT NULL
      AND used_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
      AND (used_reservation_id IS NULL OR used_reservation_id = ?)
    ORDER BY used_at DESC
    LIMIT 1
');
$stmt->execute([$customerId, $reservationId]);
$coupon = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'coupon' => $coupon ? [
        'id'            => (int)$coupon['id'],
        'code'          => $coupon['code'],
        'description'   => $coupon['description'],
        'discount'      => (int)$coupon['discount'],
        'discount_type' => $coupon['discount_type'] ?? 'amount',
        'discount_rate' => $coupon['discount_rate'] !== null ? (int)$coupon['discount_rate'] : null,
    ] : null
], JSON_UNESCAPED_UNICODE);
