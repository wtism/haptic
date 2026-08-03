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

$lineUserId   = $input['line_user_id'] ?? '';
$message      = trim($input['message'] ?? '');
$couponMode   = $input['coupon_mode'] ?? ''; // 'owned' | 'issue' | ''
$couponRefId  = (int)($input['coupon_ref_id'] ?? 0); // owned: coupons.id / issue: coupon_templates.id

if (!$lineUserId || !$message) {
    echo json_encode(['success' => false, 'error' => 'パラメータが不正です']);
    exit;
}

require_once dirname(__DIR__) . '/lib/line.php';
require_once dirname(__DIR__) . '/lib/flex.php';
require_once dirname(__DIR__) . '/lib/qr.php';

$db = db();

// ── クーポン添付処理 ──
$couponFlex = null;
if (in_array($couponMode, ['owned', 'issue'], true) && $couponRefId) {
    $stmt = $db->prepare('SELECT id FROM customers WHERE line_user_id = ?');
    $stmt->execute([$lineUserId]);
    $customerId = $stmt->fetchColumn();

    if (!$customerId) {
        echo json_encode(['success' => false, 'error' => 'お客様情報が見つかりません']);
        exit;
    }

    if ($couponMode === 'issue') {
        // テンプレートから新規発行
        $stmt = $db->prepare('SELECT * FROM coupon_templates WHERE id = ? AND is_active = 1');
        $stmt->execute([$couponRefId]);
        $tmpl = $stmt->fetch();
        if (!$tmpl) { echo json_encode(['success' => false, 'error' => 'クーポンテンプレートが見つかりません']); exit; }

        $code      = strtoupper(substr(md5(uniqid($customerId . '_' . $couponRefId, true)), 0, 8));
        $expiredAt = $tmpl['valid_days'] ? date('Y-m-d', strtotime('+' . (int)$tmpl['valid_days'] . ' days')) : null;

        $db->prepare('
            INSERT INTO coupons (customer_id, code, description, discount, discount_type, discount_rate, coupon_type, expired_at)
            VALUES (?,?,?,?,?,?,?,?)
        ')->execute([
            $customerId, $code, $tmpl['description'], $tmpl['discount'],
            $tmpl['discount_type'], $tmpl['discount_rate'], $tmpl['coupon_type'] ?: 'manual', $expiredAt,
        ]);
        $couponId = (int)$db->lastInsertId();
    } else {
        // 保有クーポンから選択
        $stmt = $db->prepare('SELECT id FROM coupons WHERE id = ? AND customer_id = ? AND used_at IS NULL');
        $stmt->execute([$couponRefId, $customerId]);
        $couponId = $stmt->fetchColumn();
        if (!$couponId) { echo json_encode(['success' => false, 'error' => '対象のクーポンが見つかりません（使用済みの可能性があります）']); exit; }
    }

    $stmt = $db->prepare('SELECT * FROM coupons WHERE id = ?');
    $stmt->execute([$couponId]);
    $coupon = $stmt->fetch();

    $token   = substr(hash_hmac('sha256', $coupon['code'], env('CRON_SECRET', 'irodori_cron_2024')), 0, 16);
    $scanUrl = 'https://haptic.irodori.tokyo/coupon/check.php?code=' . $coupon['code'] . '&t=' . $token;
    $qrUrl   = null;
    try {
        $qrUrl = generateQrCodeFile($scanUrl, 'coupon_' . $coupon['code']);
    } catch (Throwable $e) {
        // QR生成失敗時はテキストのみのカードにする
    }
    $couponFlex = flexCouponCard($coupon, $qrUrl);
}

// ── 送信 ──
$messages = [['type' => 'text', 'text' => $message]];
if ($couponFlex) $messages[] = $couponFlex;

$ch = curl_init('https://api.line.me/v2/bot/message/push');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['to' => $lineUserId, 'messages' => $messages], JSON_UNESCAPED_UNICODE),
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
    auditLog('line_push', 'customer', 0, "LINE送信：" . mb_substr($message, 0, 50) . ($couponFlex ? '（クーポン添付）' : ''));
    echo json_encode(['success' => true]);
} else {
    $err = json_decode($res, true);
    echo json_encode(['success' => false, 'error' => $err['message'] ?? "HTTP {$code}"]);
}
