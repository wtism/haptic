<?php
// ============================================================
// lib/line.php  - LINE Messaging API送信
// ============================================================

require_once __DIR__ . '/../config/config.php';

/**
 * Reply送信
 */
function lineReply(string $replyToken, array $messages): void
{
    lineSend('https://api.line.me/v2/bot/message/reply', [
        'replyToken' => $replyToken,
        'messages'   => $messages,
    ]);
}

/**
 * Push送信（管理者通知など）
 */
function linePush(string $to, array $messages): void
{
    lineSend('https://api.line.me/v2/bot/message/push', [
        'to'       => $to,
        'messages' => $messages,
    ]);
}

/**
 * テキストメッセージを組み立て
 */
function textMessage(string $text): array
{
    return ['type' => 'text', 'text' => $text];
}

/**
 * クイックリプライ付きテキストメッセージ
 */
function quickReplyMessage(string $text, array $items): array
{
    $actions = [];
    foreach ($items as $label) {
        $actions[] = [
            'type'   => 'action',
            'action' => [
                'type'  => 'message',
                'label' => $label,
                'text'  => $label,
            ],
        ];
    }

    return [
        'type' => 'text',
        'text' => $text,
        'quickReply' => ['items' => $actions],
    ];
}

/**
 * 確認用ボタンメッセージ（Flex）
 */
function confirmMessage(string $text, string $yesLabel, string $noLabel): array
{
    return [
        'type' => 'template',
        'altText' => $text,
        'template' => [
            'type'    => 'confirm',
            'text'    => $text,
            'actions' => [
                ['type' => 'message', 'label' => $yesLabel, 'text' => $yesLabel],
                ['type' => 'message', 'label' => $noLabel,  'text' => $noLabel],
            ],
        ],
    ];
}

/**
 * 共通送信処理
 */
function lineSend(string $url, array $payload): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
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

    // ログ出力（デバッグ用）
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $entry = date('Y-m-d H:i:s') . " [LINE] {$url} HTTP:{$code} " . $res . PHP_EOL;
    file_put_contents($logDir . '/webhook_' . date('Y-m-d') . '.log', $entry, FILE_APPEND | LOCK_EX);
}

/**
 * ローディングアニメーション表示
 * Claude API呼び出し前に送信する
 */
function lineShowLoading(string $lineUserId, int $seconds = 20): void
{
    lineSend('https://api.line.me/v2/bot/chat/loading/start', [
        'chatId'            => $lineUserId,
        'loadingSeconds'    => $seconds,
    ]);
}
