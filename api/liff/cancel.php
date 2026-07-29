<?php
// ============================================================
// api/liff/cancel.php - 予約キャンセル（マイページから）
// POST {line_uid, reservation_id}
// ============================================================
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/lib/line.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err(405, '許可されていないメソッドです');

$body    = json_body();
$lineUid = trim($body['line_uid'] ?? '');
$rid     = (int)($body['reservation_id'] ?? 0);

if (!$lineUid || !preg_match('/^U[0-9a-f]{32}$/i', $lineUid)) json_err(400, 'LINEユーザーIDが不正です');
if (!$rid) json_err(422, '予約IDが不正です');

$db = db();

// ── 本人確認 ──
$stmt = $db->prepare('SELECT id FROM customers WHERE line_user_id = ?');
$stmt->execute([$lineUid]);
$customer = $stmt->fetch();
if (!$customer) json_err(404, 'お客様情報が見つかりません');

// ── 予約確認（自分の予約のみ） ──
$stmt = $db->prepare('
    SELECT r.*, m.name AS menu_name
    FROM reservations r
    LEFT JOIN menus m ON r.menu_id = m.id
    WHERE r.id = ? AND r.customer_id = ?
');
$stmt->execute([$rid, $customer['id']]);
$rsv = $stmt->fetch();
if (!$rsv) json_err(404, 'ご予約が見つかりません');
if (!in_array($rsv['status'], ['pending', 'confirmed'])) json_err(409, 'このご予約はキャンセルできません');
if (strtotime($rsv['start_at']) <= time()) {
    json_err(409, '開始時刻を過ぎたご予約はこちらからキャンセルできません。お手数ですがLINEトークでご連絡ください🙏');
}

// ── キャンセル処理 ──
$db->beginTransaction();
try {
    $note = trim(($rsv['note'] ? $rsv['note'] . "\n" : '') . 'お客様がLINE予約画面からキャンセル（' . date('Y-m-d H:i') . '）');
    $db->prepare("UPDATE reservations SET status='cancelled', note=? WHERE id=?")->execute([$note, $rid]);
    $db->prepare('DELETE FROM reminders WHERE reservation_id = ? AND sent_flag = 0')->execute([$rid]);
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    json_err(500, 'キャンセル処理に失敗しました');
}

// ── LINE通知（失敗しても処理は成立） ──
$snap = json_decode($rsv['menu_snapshot'] ?? '', true) ?: [];
$menuName = $rsv['menu_name'] ?? ($snap['menu'] ?? '');
$dow = ['日','月','火','水','木','金','土'][date('w', strtotime($rsv['start_at']))];
$dt  = date('n月j日', strtotime($rsv['start_at'])) . "（{$dow}）" . date('H:i', strtotime($rsv['start_at']));
try {
    linePush($lineUid, [textMessage(
        "ご予約をキャンセルしました🙏\n\n" .
        "📅 {$dt}〜\n" .
        "✂️ {$menuName}\n\n" .
        "またのご予約をお待ちしております😊"
    )]);
} catch (Throwable $e) {
    // 通知失敗は無視
}

json_ok(['cancelled_id' => $rid]);
