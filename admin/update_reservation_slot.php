<?php
// admin/update_reservation_slot.php  - タイムテーブルD&D更新API
require_once __DIR__ . '/auth.php';
requireLogin();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

// CSRF検証
if (($input['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$id      = (int)($input['id'] ?? 0);
$staffId = $input['staff_id'] ? (int)$input['staff_id'] : null;
$startAt = $input['start_at'] ?? '';
$endAt   = $input['end_at']   ?? '';

if (!$id || !$startAt || !$endAt) {
    echo json_encode(['success' => false, 'error' => 'パラメータ不正']);
    exit;
}

// 日時バリデーション
if (!strtotime($startAt) || !strtotime($endAt)) {
    echo json_encode(['success' => false, 'error' => '日時が不正です']);
    exit;
}

$db = db();

// 予約存在確認
$stmt = $db->prepare('SELECT id FROM reservations WHERE id=?');
$stmt->execute([$id]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'error' => '予約が見つかりません']);
    exit;
}

// 更新
$db->prepare('
    UPDATE reservations
    SET staff_id=?, start_at=?, end_at=?, updated_by=?, updated_at=NOW()
    WHERE id=?
')->execute([$staffId, $startAt, $endAt, currentAdminId(), $id]);

auditLog('update', 'reservation', $id, "D&D移動：{$startAt}〜{$endAt} staff:{$staffId}");

echo json_encode(['success' => true]);
