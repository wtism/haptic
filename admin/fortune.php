<?php
// admin/fortune.php  - 運勢生成API（サーバーサイド）
require_once __DIR__ . '/auth.php';
requireLogin();

header('Content-Type: application/json');

$zodiac   = trim($_GET['zodiac'] ?? '');
$customer = trim($_GET['customer'] ?? 'お客様');

if (!$zodiac) {
    echo json_encode(['error' => 'zodiac required']);
    exit;
}

// Claude APIで運勢生成
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . env('CLAUDE_API_KEY'),
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 400,
        'system'     => '美容サロン向けの運勢アドバイザーです。星座から今日の運勢を生成します。必ずJSON形式のみで返答。{"score":85,"message":"運勢メッセージ2-3文","lucky_color":"カラー名","lucky_color_hex":"#xxxxxx","lucky_item":"アイテム名","lucky_item_emoji":"絵文字1文字","advice":"ヘアケア・美容に関する一言"}',
        'messages'   => [[
            'role'    => 'user',
            'content' => '今日（' . date('Y年m月d日') . '）の' . $zodiac . 'の運勢をJSONで生成してください',
        ]],
    ], JSON_UNESCAPED_UNICODE),
]);

$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    echo json_encode(['error' => 'API error ' . $code]);
    exit;
}

$data = json_decode($res, true);
$text = $data['content'][0]['text'] ?? '{}';
$text = preg_replace('/```json|```/', '', $text);

$fortune = json_decode(trim($text), true);
if (!$fortune) {
    $fortune = [
        'score'            => 70,
        'message'          => '今日も素敵な一日になりそうです✨',
        'lucky_color'      => 'グリーン',
        'lucky_color_hex'  => '#6B9E8A',
        'lucky_item'       => '花',
        'lucky_item_emoji' => '🌸',
        'advice'           => '今日のヘアスタイルで気分をアップしましょう！',
    ];
}

echo json_encode($fortune, JSON_UNESCAPED_UNICODE);
