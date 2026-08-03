<?php
// ============================================================
// admin/api/list_coupons.php - LINE送信モーダル用：クーポン一覧
// GET ?line_user_id=Uxxxx
// 返却: 顧客の保有クーポン（未使用・期限内）＋発行可能なクーポンテンプレート一覧
// ============================================================
require_once dirname(__DIR__) . '/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$lineUserId = trim($_GET['line_user_id'] ?? '');
if (!$lineUserId) { echo json_encode(['success' => false, 'error' => 'line_user_idが必要です']); exit; }

$db = db();

$stmt = $db->prepare('SELECT id FROM customers WHERE line_user_id = ?');
$stmt->execute([$lineUserId]);
$customerId = $stmt->fetchColumn();
if (!$customerId) { echo json_encode(['success' => false, 'error' => 'お客様が見つかりません']); exit; }

function couponLabel(array $c): string
{
    return ($c['discount_type'] ?? 'amount') === 'percent'
        ? ($c['discount_rate'] ?? 0) . '%OFF'
        : '¥' . number_format($c['discount'] ?? 0) . 'OFF';
}

// 保有クーポン（未使用・期限内）
$stmt = $db->prepare('
    SELECT id, code, description, discount, discount_type, discount_rate, expired_at
    FROM coupons
    WHERE customer_id = ? AND used_at IS NULL AND (expired_at IS NULL OR expired_at >= NOW())
    ORDER BY expired_at IS NULL, expired_at
');
$stmt->execute([$customerId]);
$owned = array_map(fn($c) => [
    'id'          => (int)$c['id'],
    'label'       => $c['description'] . '（' . couponLabel($c) . '）' . ($c['expired_at'] ? ' - ' . date('n/j', strtotime($c['expired_at'])) . 'まで' : ''),
], $stmt->fetchAll());

// 発行可能なテンプレート
$templates = $db->query('SELECT id, name, description, discount, discount_type, discount_rate, valid_days FROM coupon_templates WHERE is_active = 1 ORDER BY display_order')->fetchAll();
$issuable = array_map(fn($t) => [
    'id'    => (int)$t['id'],
    'label' => $t['name'] . '（' . couponLabel($t) . '）' . ($t['valid_days'] ? ' - 発行から' . (int)$t['valid_days'] . '日間' : ''),
], $templates);

echo json_encode([
    'success'  => true,
    'owned'    => $owned,
    'issuable' => $issuable,
], JSON_UNESCAPED_UNICODE);
