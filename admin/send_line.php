<?php
// admin/send_line.php  - 管理画面からLINE送信API
require_once __DIR__ . '/auth.php';
requireLogin();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

// CSRF検証
if (($input['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$lineUserId = $input['line_user_id'] ?? '';
$message    = trim($input['message'] ?? '');

if (!$lineUserId || !$message) {
    echo json_encode(['success' => false, 'error' => 'パラメータが不正です']);
    exit;
}

require_once dirname(__DIR__) . '/lib/line.php';

$ch = curl_init('https://api.line.me/v2/bot/message/push');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'to'       => $lineUserId,
        'messages' => [['type' => 'text', 'text' => $message]],
    ], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . env('LINE_CHANNEL_ACCESS_TOKEN'),
    ],
    CURLOPT_TIMEOUT => 10,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === 200) {
    // 送信ログ
    auditLog('line_push', 'customer', 0, "LINE送信：" . mb_substr($message, 0, 50));
    echo json_encode(['success' => true]);
} else {
    $err = json_decode($res, true);
    echo json_encode(['success' => false, 'error' => $err['message'] ?? "HTTP {$code}"]);
}
