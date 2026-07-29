<?php
// ============================================================
// lib/claude.php  - Claude API統合
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

/**
 * スタッフ一覧取得（システムプロンプト用）
 */
function getActiveStaff(): array
{
    $stmt = db()->query('SELECT name FROM staff WHERE is_active = 1 ORDER BY display_order');
    return array_column($stmt->fetchAll(), 'name');
}

/**
 * スタッフ担当メニュー一覧（システムプロンプト用）
 */
function getStaffMenuText(): string
{
    $stmt = db()->query('SELECT name, can_cut, can_color, can_perm, can_treatment FROM staff WHERE is_active = 1 ORDER BY display_order');
    $rows = $stmt->fetchAll();
    $lines = [];
    foreach ($rows as $r) {
        $menus = [];
        if ($r['can_cut'])       $menus[] = 'カット';
        if ($r['can_color'])     $menus[] = 'カラー';
        if ($r['can_perm'])      $menus[] = 'パーマ';
        if ($r['can_treatment']) $menus[] = 'トリートメント';
        // カット+カラー両対応なら複合メニューも可能
        if ($r['can_cut'] && $r['can_color']) $menus[] = 'カット＋カラー';
        $lines[] = "・{$r['name']}：" . implode('・', $menus);
    }
    return implode("
", $lines);
}

/**
 * メニュー一覧取得（システムプロンプト用）
 */
function getActiveMenus(): array
{
    $stmt = db()->query('
        SELECT category, name, duration_min, price
        FROM menus
        WHERE is_active = 1
        ORDER BY display_order
    ');
    return $stmt->fetchAll();
}

/**
 * システムプロンプト生成
 */
function buildSystemPrompt(array $customer, array $history): string
{
    $staffMenuText = getStaffMenuText();

    $menuList = '';
    foreach (getActiveMenus() as $m) {
        $menuList .= "・{$m['name']}（{$m['duration_min']}分 / ¥" . number_format($m['price']) . "）\n";
    }

    $historyText = '';
    if (!empty($history)) {
        $historyText = "\n【過去の来店履歴】\n";
        foreach ($history as $h) {
            $date = date('Y年m月d日', strtotime($h['start_at']));
            $historyText .= "・{$date} {$h['menu_name']}（担当：{$h['staff_name']}）\n";
        }
    }

    $customerName = $customer['name'] ?? '';
    $nameText     = $customerName ? "お客様のお名前：{$customerName}様" : "お客様のお名前：未登録";

    $today = date('Y年m月d日（' . ['日','月','火','水','木','金','土'][date('w')] . '）');

    return <<<PROMPT
あなたは美容室「HAPTIC」の予約受付AIアシスタントです。
LINEのトーク画面でお客様の予約サポートとヘアケア相談を行います。

【基本情報】
今日の日付：{$today}
サロン名：HAPTIC
営業時間：10:00〜19:00（最終受付18:00）
定休日：火曜日

【スタッフ・担当メニュー】
{$staffMenuText}

【メニュー】
{$menuList}

【お客様情報】
{$nameText}{$historyText}

【会話ルール】
・丁寧でフレンドリーな口調で話してください
・絵文字を適度に使って親しみやすくしてください
・予約の意図を感じたら、日時・メニュー・スタッフ指名の3点を確認してください
・日時は「来週の土曜」などの曖昧な表現も自然に解釈してください
・情報が揃ったら必ず内容を確認してから仮予約に進んでください
・営業時間外・定休日の予約は丁寧にお断りし、代替日を提案してください

【ヘアケア相談モード（重要）】
予約以外のメッセージ（ヘアケアの悩み・スタイルの相談・美容の質問など）は、
プロの美容師として親身に回答してください。
例：「髪がパサパサ」「くせ毛が気になる」「カラーが褪色する」「おすすめのシャンプーは？」
→ 具体的なアドバイスを提供し、必要に応じてご来店を提案してください。

【予約以外の無関係な話題】
天気・ニュース・料理など美容と無関係な話題には、
「美容室のLINEなので、ヘアのご相談や予約に関することでしたら何でもお答えします😊」
のように自然に誘導してください。

【重要】
お客様が予約を希望していると判断したら（「予約したい」「行きたい」「空いてる？」など）、
返答の末尾に必ず以下を含めてください（お客様には見えません）：
<!--INTENT:booking-->

日時・メニュー・スタッフはシステムが順番にご案内するので、Claudeが聞く必要はありません。
PROMPT;
}

/**
 * Claude APIに送信して返答を得る
 */
function askClaude(array $messages, string $systemPrompt): string
{
    $payload = json_encode([
        'model'      => env('CLAUDE_MODEL', 'claude-sonnet-4-5'),
        'max_tokens' => 1000,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: '           . env('CLAUDE_API_KEY'),
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new RuntimeException("Claude API error: HTTP {$code} / {$res}");
    }

    $data = json_decode($res, true);
    return $data['content'][0]['text'] ?? '';
}

/**
 * 返答から予約意図を抽出
 * <!--INTENT:booking--> があれば予約フローへ
 */
function extractIntent(string $text): ?string
{
    if (preg_match('/<!--INTENT:(.*?)-->/', $text, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * 返答からINTENTコメントを除去（LINEに送る前に）
 */
function cleanResponse(string $text): string
{
    return trim(preg_replace('/<!--INTENT:.*?-->/', '', $text));
}
