<?php
/**
 * admin/api/ai_generate_line.php
 * LINEテンプレートAI生成 中継エンドポイント
 * POST JSON: { purpose, tone, extra, ref }
 */
require_once dirname(__DIR__) . '/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { http_response_code(400); echo json_encode(['error' => 'invalid input']); exit; }

$purpose = trim($input['purpose'] ?? '');
$tone    = trim($input['tone']    ?? 'フレンドリー・親しみやすい');
$extra   = trim($input['extra']   ?? '');
$ref     = trim($input['ref']     ?? '');

if (!$purpose) { http_response_code(400); echo json_encode(['error' => '用途・シーンは必須です']); exit; }

// ショップ名を取得（あれば）
try {
    $db       = db();
    $shopName = $db->query('SELECT shop_name FROM shop_settings LIMIT 1')->fetchColumn() ?: '美容室';
} catch (Throwable $e) {
    $shopName = '美容室';
}

$prompt = "あなたは{$shopName}のLINEメッセージ文章のプロです。
以下の条件でLINEメッセージのテンプレート文章を1つ作成してください。

【用途・シーン】{$purpose}
【トーン】{$tone}";

if ($extra !== '') $prompt .= "\n【含めたい要素】{$extra}";
if ($ref   !== '') $prompt .= "\n【参考文章（この文体・雰囲気に寄せてください）】\n{$ref}";

$prompt .= "

ルール：
- {name} という変数はお客様の名前に置換されます。冒頭で「{name}様」と使ってください
- 絵文字を適度に使い、LINEらしい温かみのある文章にする
- 200文字前後
- 文章のみ出力し、説明・前置き・タイトルは一切不要";

try {
    require_once dirname(dirname(__DIR__)) . '/lib/claude.php';

    $text = askClaude(
        [['role' => 'user', 'content' => $prompt]],
        "あなたは美容室のLINEメッセージ作成の専門家です。指示通りの文章のみを出力してください。"
    );

    echo json_encode(['success' => true, 'text' => trim($text)], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
