<?php
// ============================================================
// webhook/webhook.php
// フロー: 予約意図→メニュー→日付→時間→スタッフ→クーポン→確認→仮予約
// 配置: /home/mogans/www/haptic.irodori.tokyo/webhook/webhook.php
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/lib/session.php';
require_once dirname(__DIR__) . '/lib/customer.php';
require_once dirname(__DIR__) . '/lib/claude.php';
require_once dirname(__DIR__) . '/lib/line.php';
require_once dirname(__DIR__) . '/lib/flex.php';
require_once dirname(__DIR__) . '/lib/availability.php';

function writeLog(string $level, string $msg, array $data = []): void
{
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);
    $entry = date('Y-m-d H:i:s') . " [{$level}] {$msg}";
    if ($data) $entry .= ' ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    file_put_contents($logDir . '/webhook_' . date('Y-m-d') . '.log', $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function verifySignature(string $body, string $signature): bool
{
    $hash = base64_encode(hash_hmac('sha256', $body, env('LINE_CHANNEL_SECRET'), true));
    return hash_equals($hash, $signature);
}

/**
 * LIFF予約画面URL（LIFF_ID未設定ならnull → チャットフローにフォールバック）
 */
function liffBookingUrl(): ?string
{
    $liffId = env('LIFF_ID', '');
    return ($liffId && $liffId !== '__FILL_ME__') ? 'https://liff.line.me/' . $liffId : null;
}

// ============================================================
// Cron処理（セッションタイムアウト）
// ============================================================
if (isset($_GET['cron']) && $_GET['cron'] === env('CRON_SECRET', 'irodori_cron_2024')) {
    handleCronTimeout();
    exit;
}

function handleCronTimeout(): void
{
    $db  = db();
    $now = time();

    $stmt = $db->query("
        SELECT s.line_user_id, s.phase, s.temp_data, s.updated_at,
               c.name AS customer_name
        FROM line_sessions s
        LEFT JOIN customers c ON c.line_user_id = s.line_user_id
        WHERE s.phase NOT IN ('idle','ask_name')
    ");
    $sessions = $stmt->fetchAll();

    $reminded = 0; $reset = 0;

    foreach ($sessions as $sess) {
        $elapsed    = ($now - strtotime($sess['updated_at'])) / 60;
        $lineUserId = $sess['line_user_id'];
        $name       = $sess['customer_name'] ? $sess['customer_name'] . 'さん' : 'お客様';
        $tempData   = json_decode($sess['temp_data'] ?? '{}', true) ?? [];

        if ($elapsed >= 15) {
            // 15分 → リセット
            $db->prepare("UPDATE line_sessions SET phase='idle', temp_data='{}', messages_json='[]', updated_at=NOW() WHERE line_user_id=?")->execute([$lineUserId]);
            linePush($lineUserId, [textMessage(
                "お時間が経過したため、予約フローをリセットしました🙏

" .
                "改めて「予約したい」とお送りいただければご案内します😊"
            )]);
            $reset++;

        } elseif ($elapsed >= 10 && !isset($tempData['_reminded'])) {
            // 10分 → リマインド（1回のみ）
            $tempData['_reminded'] = true;
            // updated_atを更新しない（タイムアウト計算に影響させないため）
            $db->prepare("UPDATE line_sessions SET temp_data=? WHERE line_user_id=?")->execute([
                json_encode($tempData, JSON_UNESCAPED_UNICODE), $lineUserId
            ]);
            // updated_atを元に戻す
            $db->prepare("UPDATE line_sessions SET updated_at=? WHERE line_user_id=?")->execute([
                $sess['updated_at'], $lineUserId
            ]);
            linePush($lineUserId, [textMessage(
                "{$name}、予約の続きはいかがでしょうか？😊

" .
                "このままご操作がない場合、5分後に自動でリセットされます。
" .
                "「最初から」と送るとやり直せます！"
            )]);
            $reminded++;
        }
    }

    // リマインド送信処理
    $reminderStmt = $db->query("
        SELECT r.*, res.start_at, res.menu_id, res.staff_id, res.menu_snapshot,
               c.line_user_id, c.name AS customer_name,
               m.name AS menu_name, s.name AS staff_name
        FROM reminders r
        JOIN reservations res ON r.reservation_id = res.id
        JOIN customers c ON res.customer_id = c.id
        LEFT JOIN menus m ON res.menu_id = m.id
        LEFT JOIN staff s ON res.staff_id = s.id
        WHERE r.sent_flag = 0
          AND r.send_at <= NOW()
          AND res.status = 'confirmed'
    ");
    $reminders   = $reminderStmt->fetchAll();
    $reminderSent = 0;

    foreach ($reminders as $rem) {
        if (!$rem['line_user_id']) continue;

        $dow      = ['日','月','火','水','木','金','土'][date('w', strtotime($rem['start_at']))];
        $dt       = date('m月d日（'.$dow.'） H:i', strtotime($rem['start_at']));
        $name     = $rem['customer_name'] ? $rem['customer_name'] . '様' : 'お客様';
        $staff    = $rem['staff_name'] ?? 'おまかせ';
        // 複数メニューはスナップショットの結合ラベルを優先
        $remSnap  = json_decode($rem['menu_snapshot'] ?? '', true) ?: [];
        $menu     = $remSnap['menu'] ?? ($rem['menu_name'] ?? '');

        linePush($rem['line_user_id'], [textMessage(
            "【ご予約リマインド】

" .
            "{$name}、明日のご予約のご確認です✨

" .
            "📅 {$dt}〜
" .
            "✂️ {$menu}
" .
            "👤 担当：{$staff}

" .
            "ご来店をお待ちしております😊
" .
            "ご不明な点はこちらのトークからどうぞ🙏"
        )]);

        $db->prepare('UPDATE reminders SET sent_flag=1, sent_at=NOW() WHERE id=?')->execute([$rem['id']]);
        $reminderSent++;
    }

    // 誕生日クーポン自動配信（coupon_templatesの「誕生日」テンプレートに連動）
    $todayMD  = date('m-d');
    $thisYear = (int)date('Y');
    $bdaySent = 0;

    $bdayTpl = null;
    try {
        $bdayTpl = $db->query("
            SELECT * FROM coupon_templates
            WHERE coupon_type = 'birthday' AND is_active = 1
            ORDER BY display_order LIMIT 1
        ")->fetch() ?: null;
    } catch (Throwable $e) {}

    $bdayCustomers = [];
    if ($bdayTpl) {
        $bdayStmt = $db->query("
            SELECT c.*
            FROM customers c
            WHERE c.birthdate IS NOT NULL
              AND DATE_FORMAT(c.birthdate, '%m-%d') = '{$todayMD}'
              AND c.line_user_id IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM birthday_coupon_logs bl
                  WHERE bl.customer_id = c.id AND bl.sent_year = {$thisYear}
              )
        ");
        $bdayCustomers = $bdayStmt->fetchAll();
    }

    foreach ($bdayCustomers as $c) {
        // テンプレートから割引内容を取得
        $tplName      = $bdayTpl['name'] ?: 'お誕生日クーポン';
        $discountType = $bdayTpl['discount_type'] ?? 'amount';
        $discountVal  = (int)$bdayTpl['discount'];
        $discountRate = $bdayTpl['discount_rate'] !== null ? (int)$bdayTpl['discount_rate'] : null;
        $validDays    = max(1, (int)($bdayTpl['valid_days'] ?: 30));
        $discountLabel = ($discountType === 'percent' && $discountRate)
            ? "{$discountRate}%OFF"
            : '¥' . number_format($discountVal);

        // クーポン発行
        $code = strtoupper(substr(md5(uniqid($c['id'] . $thisYear, true)), 0, 8));
        $expired = date('Y-m-d', strtotime("+{$validDays} days"));

        $db->prepare('
            INSERT INTO coupons (customer_id, code, description, discount_type, discount, discount_rate, coupon_type, expired_at)
            VALUES (?,?,?,?,?,?,?,?)
        ')->execute([$c['id'], $code, $tplName, $discountType, $discountVal, $discountRate, 'birthday', $expired]);
        $couponId = (int)$db->lastInsertId();

        // 送信ログ登録
        $db->prepare('
            INSERT INTO birthday_coupon_logs (customer_id, sent_year, coupon_id)
            VALUES (?,?,?)
        ')->execute([$c['id'], $thisYear, $couponId]);

        // QRコード生成＋LINE Push
        $name    = $c['name'] ? $c['name'] . '様' : 'お客様';
        $token   = substr(hash_hmac('sha256', $code, env('CRON_SECRET', 'irodori_cron_2024')), 0, 16);
        $scanUrl = 'https://haptic.irodori.tokyo/coupon/check.php?code=' . $code . '&t=' . $token;

        // QR画像生成
        $qrUrl = null;
        try {
            require_once '/home/mogans/www/haptic.irodori.tokyo/lib/qr.php';
            $qrUrl = generateQrCodeFile($scanUrl, 'coupon_' . $code);
        } catch (Throwable $e) {
            writeLog('ERROR', 'QR generate failed: ' . $e->getMessage());
        }

        $messages = [];

        // テキストメッセージ
        $messages[] = textMessage(
            "🎂 {$name}、お誕生日おめでとうございます！

" .
            "いつもご来店ありがとうございます✨
" .
            "心ばかりのお誕生日クーポンをプレゼントします🎁"
        );

        // Flexクーポン（QR付き）
        if ($qrUrl) {
            $messages[] = [
                'type'    => 'flex',
                'altText' => "🎫 お誕生日クーポン（{$code}）",
                'contents' => [
                    'type'   => 'bubble',
                    'styles' => ['header' => ['backgroundColor' => '#E8746A']],
                    'header' => [
                        'type' => 'box', 'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => '🎂 お誕生日クーポン', 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'xl'],
                            ['type' => 'text', 'text' => $tplName, 'color' => '#ffffff', 'size' => 'sm', 'margin' => 'xs'],
                        ],
                    ],
                    'body' => [
                        'type' => 'box', 'layout' => 'vertical',
                        'contents' => [
                            ['type' => 'text', 'text' => $name, 'weight' => 'bold', 'size' => 'lg'],
                            ['type' => 'separator', 'margin' => 'md'],
                            ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
                             'contents' => [
                                ['type' => 'text', 'text' => '💰 割引', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                                ['type' => 'text', 'text' => $discountLabel, 'size' => 'sm', 'weight' => 'bold', 'flex' => 3],
                             ]],
                            ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
                             'contents' => [
                                ['type' => 'text', 'text' => '📅 有効期限', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                                ['type' => 'text', 'text' => date('Y年m月d日', strtotime($expired)) . 'まで', 'size' => 'sm', 'weight' => 'bold', 'flex' => 3],
                             ]],
                            ['type' => 'separator', 'margin' => 'md'],
                            ['type' => 'image', 'url' => $qrUrl, 'size' => 'md', 'aspectRatio' => '1:1', 'aspectMode' => 'fit', 'margin' => 'md', 'align' => 'center'],
                            ['type' => 'text', 'text' => 'ご来店時にQRコードをスタッフにご提示ください', 'size' => 'xs', 'color' => '#888888', 'wrap' => true, 'align' => 'center', 'margin' => 'sm'],
                            ['type' => 'text', 'text' => $code, 'size' => 'lg', 'weight' => 'bold', 'align' => 'center', 'margin' => 'md', 'color' => '#E8746A'],
                        ],
                    ],
                ],
            ];
        } else {
            // QR生成失敗時はテキストのみ
            $messages[] = textMessage("🎫 クーポンコード：{$code}
💰 割引：{$discountLabel}
📅 有効期限：{$expired}まで

ご来店の際にスタッフにコードをお伝えください😊");
        }

        linePush($c['line_user_id'], $messages);

        $bdaySent++;
    }

    // 物販リマインド送信
    $saleRemindStmt = $db->query("
        SELECT ps.*, p.name AS product_name, p.maker,
               c.line_user_id, c.name AS customer_name
        FROM product_sales ps
        JOIN products p ON ps.product_id = p.id
        JOIN customers c ON ps.customer_id = c.id
        WHERE ps.remind_enabled = 1
          AND ps.reminded_at IS NULL
          AND ps.remind_months IS NOT NULL
          AND DATE_ADD(ps.sold_at, INTERVAL ps.remind_months MONTH) <= CURDATE()
          AND c.line_user_id IS NOT NULL
    ");
    $saleReminders = $saleRemindStmt->fetchAll();
    $saleReminderSent = 0;

    foreach ($saleReminders as $sr) {
        $name    = $sr['customer_name'] ? $sr['customer_name'] . '様' : 'お客様';
        $product = $sr['product_name'] . ($sr['maker'] ? '（' . $sr['maker'] . '）' : '');

        linePush($sr['line_user_id'], [textMessage(
            "{$name}

" .
            "以前ご購入いただいた
【{$product}】
はいかがでしたか？😊

" .
            "そろそろ補充のタイミングかもしれません✨
" .
            "ご来店の際はぜひスタッフにお声がけください🙏"
        )]);

        $db->prepare('UPDATE product_sales SET reminded_at=NOW() WHERE id=?')->execute([$sr['id']]);
        $saleReminderSent++;
    }

    echo date('Y-m-d H:i:s') . " - reminded:{$reminded}, reset:{$reset}, checked:" . count($sessions) . ", reminder_sent:{$reminderSent}, birthday:{$bdaySent}, sale_remind:{$saleReminderSent}" . PHP_EOL;
}

// ============================================================
// メイン：即200返してからイベント処理
// ============================================================
$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

if (!verifySignature($rawBody, $signature)) {
    writeLog('WARN', 'Invalid signature');
    http_response_code(400);
    exit('Bad Request');
}

$data   = json_decode($rawBody, true);
$events = $data['events'] ?? [];

if (empty($events)) { http_response_code(200); exit('OK'); }

// LINEに即200を返す
http_response_code(200);
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level()) ob_end_flush();
    flush();
}

set_time_limit(120);

foreach ($events as $event) {
    $eventType  = $event['type'] ?? '';
    $lineUserId = $event['source']['userId'] ?? '';
    $replyToken = $event['replyToken'] ?? '';
    writeLog('INFO', "Event: {$eventType}", ['userId' => $lineUserId]);
    try {
        match ($eventType) {
            'follow'  => handleFollow($lineUserId, $replyToken),
            'message' => handleMessage($event, $lineUserId, $replyToken),
            default   => null,
        };
    } catch (Throwable $e) {
        writeLog('ERROR', $e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
        linePush($lineUserId, [textMessage("申し訳ございません、一時的にエラーが発生しました🙏\nしばらく経ってから再度お試しください。")]);
    }
}

// ============================================================
// 友達追加
// ============================================================
function handleFollow(string $lineUserId, string $replyToken): void
{
    // LINE表示名を取得
    $lineName = '';
    $ch = curl_init("https://api.line.me/v2/bot/profile/{$lineUserId}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.env('LINE_CHANNEL_ACCESS_TOKEN')], CURLOPT_TIMEOUT=>5]);
    $profileRes = curl_exec($ch); curl_close($ch);
    $profile = json_decode($profileRes, true);
    if (!empty($profile['displayName'])) $lineName = $profile['displayName'];

    $customer = getCustomer($lineUserId);
    if (!$customer) {
        createCustomer($lineUserId, $lineName);
    } elseif ($lineName) {
        db()->prepare('UPDATE customers SET line_name=? WHERE line_user_id=?')->execute([$lineName, $lineUserId]);
    }
    $session = getSession($lineUserId);

    $liffUrl = liffBookingUrl();
    if ($liffUrl) {
        // LIFF予約：登録も予約画面内で行うので、チャットでは聞かない
        $session['phase'] = 'idle';
        saveSession($session);
        $greeting = $lineName ? "{$lineName}さん、" : '';
        lineReply($replyToken, [
            textMessage(
                "HAPTICのLINEアカウントへようこそ✨\n\n" .
                "{$greeting}はじめまして😊\n" .
                "ご予約は下のボタンからどうぞ！\n" .
                "ヘアのご相談はこのままトークにお送りください💬"
            ),
            flexLiffBooking($liffUrl),
        ]);
    } else {
        // フォールバック：チャット登録フロー
        $session['phase'] = 'ask_name';
        saveSession($session);
        $greeting = $lineName ? "{$lineName}さん、は" : "は";
        lineReply($replyToken, [textMessage(
            "HAPTICのLINEアカウントへようこそ✨\n\n" .
            "{$greeting}じめまして😊\n" .
            "ご予約・ヘアのご相談など、お気軽にどうぞ！\n\n" .
            "まず、お名前を教えていただけますか？"
        )]);
    }
}

// ============================================================
// メッセージ振り分け
// ============================================================
function handleMessage(array $event, string $lineUserId, string $replyToken): void
{
    if (($event['message']['type'] ?? '') !== 'text') {
        linePush($lineUserId, [textMessage('テキストメッセージでお送りください🙏')]);
        return;
    }

    $text     = trim($event['message']['text'] ?? '');
    $customer = getCustomer($lineUserId);
    if (!$customer) { createCustomer($lineUserId); $customer = getCustomer($lineUserId); }
    $session = getSession($lineUserId);
    writeLog('INFO', 'Message', ['text' => $text, 'phase' => $session['phase']]);

    // チャット登録フローはLIFF未設定時のみ（LIFF有効時は予約画面内で登録）
    if (!liffBookingUrl()) {
        if (empty($customer['name'])) {
            handleNameRegistration($lineUserId, $replyToken, $text, $session);
            return;
        }

        // 性別未登録 → 性別取得フェーズ
        if (empty($customer['gender']) && $session['phase'] === 'ask_gender') {
            handleGenderRegistration($lineUserId, $replyToken, $text, $session, $customer);
            return;
        }

        // 誕生日未登録 → 誕生日取得フェーズ
        if (empty($customer['birthdate']) && $session['phase'] === 'ask_birthday') {
            handleBirthdayRegistration($lineUserId, $replyToken, $text, $session, $customer);
            return;
        }
    }

    // どのフェーズでも「最初から」系ワードでリセット
    $resetWords = ['最初から', 'やり直す', 'やりなおす', 'リセット', '最初に戻る', 'はじめから', 'cancel'];
    if (in_array(mb_strtolower(trim($text)), array_map('mb_strtolower', $resetWords))) {
        $session['phase']     = 'idle';
        $session['temp_data'] = [];
        $session['messages']  = [];
        saveSession($session);
        linePush($lineUserId, [textMessage(
            "承知しました！最初からやり直します😊

" .
            "「予約したい」とお送りいただければご案内します！"
        )]);
        return;
    }

    // 予約フロー中の「やめたい」意思を検出
    $bookingPhases = ['select_menu','select_date','select_time','select_staff','select_coupon','confirm_booking'];
    if (in_array($session['phase'], $bookingPhases)) {
        $stopWords = ['やめる','やめます','止める','止めます','やっぱり','やめた','いらない','中断','中止','予約しない','やめようかな','また今度','今日はいい','大丈夫です'];
        $textLower = mb_strtolower($text);
        $isStop = false;
        foreach ($stopWords as $w) {
            if (mb_strpos($textLower, mb_strtolower($w)) !== false) { $isStop = true; break; }
        }
        if ($isStop) {
            $session['temp_data']['prev_phase'] = $session['phase'];
            $session['phase'] = 'confirm_stop';
            saveSession($session);
            linePush($lineUserId, [[
                'type'    => 'flex',
                'altText' => '予約をやめますか？',
                'contents' => [
                    'type' => 'bubble',
                    'body' => [
                        'type'     => 'box',
                        'layout'   => 'vertical',
                        'contents' => [
                            ['type'=>'text','text'=>'予約をやめますか？','weight'=>'bold','size'=>'lg'],
                            ['type'=>'text','text'=>'途中まで進めた内容はリセットされます','size'=>'sm','color'=>'#888888','margin'=>'md','wrap'=>true],
                        ],
                    ],
                    'footer' => [
                        'type'     => 'box',
                        'layout'   => 'horizontal',
                        'spacing'  => 'sm',
                        'contents' => [
                            ['type'=>'button','style'=>'primary','color'=>'#e74c3c','action'=>['type'=>'message','label'=>'やめる','text'=>'予約をやめる']],
                            ['type'=>'button','style'=>'secondary','action'=>['type'=>'message','label'=>'続ける','text'=>'予約を続ける']],
                        ],
                    ],
                ],
            ]]);
            return;
        }
    }

    // confirm_stopフェーズの処理
    if ($session['phase'] === 'confirm_stop') {
        if ($text === '予約をやめる') {
            $session['phase']     = 'idle';
            $session['temp_data'] = [];
            $session['messages']  = [];
            saveSession($session);
            linePush($lineUserId, [textMessage(
                "予約をキャンセルしました🙏

" .
                "またご予約したいときは「予約したい」とお送りください😊
" .
                "ヘアのご相談もいつでもどうぞ！"
            )]);
        } else {
            $session['phase'] = $session['temp_data']['prev_phase'] ?? 'select_menu';
            saveSession($session);
            linePush($lineUserId, [textMessage("予約を続けます😊
ご希望の内容をお選びください！")]);
        }
        return;
    }

    // 「ひとつ前に戻る」処理
    if (str_starts_with($text, '戻る:')) {
        handleGoBack($lineUserId, $text, $customer, $session);
        return;
    }

    match ($session['phase']) {
        'select_menu'     => handleMenuSelect($lineUserId, $text, $customer, $session),
        'select_date'     => handleDateSelect($lineUserId, $text, $customer, $session),
        'select_time'     => handleTimeSelect($lineUserId, $text, $customer, $session),
        'select_staff'    => handleStaffSelect($lineUserId, $text, $customer, $session),
        'select_coupon'   => handleCouponSelect($lineUserId, $text, $customer, $session),
        'confirm_booking' => handleBookingAnswer($lineUserId, $text, $customer, $session),
        default           => handleConversation($lineUserId, $text, $customer, $session),
    };
}

// ============================================================
// 名前登録
// ============================================================
function handleNameRegistration(string $lineUserId, string $replyToken, string $text, array $session): void
{
    // 名前として不適切なワード（予約意図・あいさつ等）は名前登録しない
    $notNameWords = ['予約', 'よやく', 'メニュー', 'キャンセル', 'こんにちは', 'こんばんは', 'おはよう',
                     'はじめまして', 'よろしく', 'ありがとう', 'クーポン', '空いてる', 'あいてる', '料金', '値段'];
    foreach ($notNameWords as $w) {
        if (mb_strpos($text, $w) !== false) {
            lineReply($replyToken, [textMessage(
                "ご利用ありがとうございます😊\n\n" .
                "ご予約・ご相談の前に、まずお客様の【お名前】を教えていただけますか？\n\n" .
                "例：山田 花子"
            )]);
            return;
        }
    }

    $name = mb_substr($text, 0, 20);
    updateCustomer($lineUserId, ['name' => $name]);
    $session['phase'] = 'ask_gender';
    saveSession($session);
    lineReply($replyToken, [
        textMessage("{$name}様、よろしくお願いいたします✨"),
        [
            'type'    => 'flex',
            'altText' => '性別を教えてください',
            'contents' => [
                'type'   => 'bubble',
                'body'   => [
                    'type'     => 'box',
                    'layout'   => 'vertical',
                    'contents' => [
                        ['type'=>'text','text'=>'性別を教えてください😊','weight'=>'bold','size'=>'md','margin'=>'md'],
                        [
                            'type'     => 'box',
                            'layout'   => 'horizontal',
                            'margin'   => 'lg',
                            'spacing'  => 'sm',
                            'contents' => [
                                ['type'=>'button','style'=>'primary','color'=>'#6B9E8A','action'=>['type'=>'message','label'=>'👩 女性','text'=>'女性']],
                                ['type'=>'button','style'=>'primary','color'=>'#5b8fa8','action'=>['type'=>'message','label'=>'👨 男性','text'=>'男性']],
                                ['type'=>'button','style'=>'secondary','action'=>['type'=>'message','label'=>'その他','text'=>'その他']],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);
}

// ============================================================
// 性別登録
// ============================================================
function handleGenderRegistration(string $lineUserId, string $replyToken, string $text, array $session, array $customer): void
{
    $genderMap = ['女性'=>'female','男性'=>'male','その他'=>'other','female'=>'female','male'=>'male'];
    $gender = $genderMap[$text] ?? null;

    if (!$gender) {
        lineReply($replyToken, [textMessage("ボタンからお選びいただくか、「女性」「男性」「その他」とお送りください😊")]);
        return;
    }

    updateCustomer($lineUserId, ['gender' => $gender]);
    $session['phase'] = 'ask_birthday';
    saveSession($session);

    $name = $customer['name'] ?? 'お客様';
    lineReply($replyToken, [textMessage(
        "ありがとうございます✨\n\n" .
        "次に、誕生日を教えていただけますか？🎂\n\n" .
        "例：1990/04/24\n" .
        "または「スキップ」でとばせます"
    )]);
}

// ============================================================
// 誕生日登録
// ============================================================
function handleBirthdayRegistration(string $lineUserId, string $replyToken, string $text, array $session, array $customer): void
{
    $name = $customer['name'] ?? 'お客様';

    if (mb_strtolower($text) === 'スキップ' || $text === 'skip') {
        $session['phase'] = 'idle';
        saveSession($session);
        lineReply($replyToken, [textMessage(
            "了解です😊\n\n" .
            "登録完了しました！\n" .
            "「予約したい」とお送りいただければご予約のご案内をします✨\n" .
            "ヘアのご相談もお気軽にどうぞ！"
        )]);
        return;
    }

    // 日付パース
    $cleaned  = preg_replace('/[年月\/\-\.]/', '/', $text);
    $cleaned  = preg_replace('/日/', '', $cleaned);
    $parts    = explode('/', $cleaned);
    $birthdate = null;

    if (count($parts) === 3) {
        $y = (int)$parts[0]; $m = (int)$parts[1]; $d = (int)$parts[2];
        if ($y > 1900 && $y <= date('Y') && $m >= 1 && $m <= 12 && $d >= 1 && $d <= 31) {
            $birthdate = sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
    }

    if (!$birthdate) {
        lineReply($replyToken, [textMessage(
            "日付の形式が読み取れませんでした😅\n\n" .
            "例：1990/04/24 のようにお送りください\n" .
            "「スキップ」でとばすこともできます"
        )]);
        return;
    }

    updateCustomer($lineUserId, ['birthdate' => $birthdate]);
    $session['phase'] = 'idle';
    saveSession($session);

    // 星座計算
    $bm = (int)date('m', strtotime($birthdate));
    $bd = (int)date('d', strtotime($birthdate));
    $zodiacList = [
        [3,21,'牡羊座♈'],[4,20,'牡牛座♉'],[5,21,'双子座♊'],[6,21,'蟹座♋'],
        [7,23,'獅子座♌'],[8,23,'乙女座♍'],[9,23,'天秤座♎'],[10,23,'蠍座♏'],
        [11,22,'射手座♐'],[12,22,'山羊座♑'],[1,20,'水瓶座♒'],[2,19,'魚座♓'],
    ];
    $zodiac = '魚座♓';
    foreach ($zodiacList as [$zm,$zd,$zn]) {
        if (($bm==$zm && $bd>=$zd) || ($bm==$zm+1 && $bd<$zd)) { $zodiac=$zn; break; }
    }
    if ($bm==3 && $bd<21) $zodiac='魚座♓';

    lineReply($replyToken, [textMessage(
        "{$name}様、登録完了しました🎉\n\n" .
        "星座：{$zodiac}\n\n" .
        "「予約したい」とお送りいただければご予約のご案内をします✨\n" .
        "ヘアのご相談もお気軽にどうぞ！"
    )]);
}

// ============================================================
// Claude会話（予約意図を検出したら日付選択へ）
// ============================================================
function handleConversation(string $lineUserId, string $text, array $customer, array $session): void
{
    // キーワードで直接予約案内へ（Claude API不要）
    $bookingKeywords = ['予約','よやく','ヨヤク','booking','book','来店','行きたい','行けます','空いてる','あいてる','取りたい','とりたい'];
    foreach ($bookingKeywords as $kw) {
        if (mb_strpos($text, $kw) !== false) {
            $liffUrl = liffBookingUrl();
            if ($liffUrl) {
                // LIFF予約画面へ誘導
                linePush($lineUserId, [
                    textMessage("ご予約ですね😊\nこちらからどうぞ！"),
                    flexLiffBooking($liffUrl),
                ]);
            } else {
                // フォールバック：チャット予約フロー
                $session['phase']     = 'select_menu';
                $session['temp_data'] = [];
                saveSession($session);
                linePush($lineUserId, [
                    textMessage("ご予約ですね😊
ご希望のメニューをお選びください！"),
                    flexMenuSelect(getBookingMenus()),
                ]);
            }
            return;
        }
    }

    $history      = getCustomerHistory((int)$customer['id']);
    $systemPrompt = buildSystemPrompt($customer, $history);
    addMessage($session, 'user', $text);
    lineShowLoading($lineUserId);
    $response  = askClaude($session['messages'], $systemPrompt);
    $intent    = extractIntent($response);
    $cleanText = cleanResponse($response);
    addMessage($session, 'assistant', $cleanText);

    if ($intent === 'booking') {
        $liffUrl = liffBookingUrl();
        if ($liffUrl) {
            linePush($lineUserId, [
                textMessage($cleanText),
                flexLiffBooking($liffUrl),
            ]);
        } else {
            $session['phase']     = 'select_menu';
            $session['temp_data'] = [];
            saveSession($session);
            linePush($lineUserId, [
                textMessage($cleanText),
                flexMenuSelect(getBookingMenus()),
            ]);
        }
        return;
    }

    saveSession($session);
    linePush($lineUserId, [textMessage($cleanText)]);
}

// ============================================================
// ②日付選択
// ============================================================
function handleDateSelect(string $lineUserId, string $text, array $customer, array $session): void
{
    $data        = $session['temp_data'];
    $durationMin = (int)($data['duration_min'] ?? 30);
    $availableDates = getAvailableDates(60, $durationMin);

    // 「前の7日・次の7日」ボタン対応
    if (preg_match('/^カレンダー:(\d+)$/', $text, $m)) {
        $offset = (int)$m[1];
        linePush($lineUserId, [flexDateSelect($availableDates, $offset)]);
        return;
    }

    $date = preg_replace('/で$/', '', $text);

    // カレンダーにない日付をテキスト入力した場合
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $parsed = parseDate($text);
        if ($parsed) {
            $date = $parsed;
        } else {
            // 解釈できない → 再度カレンダー表示
            linePush($lineUserId, [
                textMessage("ご希望の日程をカレンダーからお選びいただくか、「5月10日」のようにお送りください😊"),
                flexDateSelect($availableDates),
            ]);
            return;
        }
    }

    // 定休日・臨時休業チェック
    if (isRegularHoliday($date) || isShopHoliday($date)) {
        linePush($lineUserId, [
            textMessage("申し訳ございません、その日はお休みをいただいております🙏\n別の日程をお選びください！"),
            flexDateSelect($availableDates),
        ]);
        return;
    }

    // その日の空き時間取得（メニュー所要時間を考慮）
    $slots = getDateAvailableSlots($date, $durationMin);

    if (empty($slots)) {
        $dow       = ['日','月','火','水','木','金','土'][date('w', strtotime($date))];
        $dateLabel = date('m月d日（' . $dow . '）', strtotime($date));
        linePush($lineUserId, [
            textMessage("{$dateLabel}は満員となっております😢\n別の日程をお選びください！"),
            flexDateSelect($availableDates),
        ]);
        return;
    }

    $data['date']         = $date;
    $session['temp_data'] = $data;
    $session['phase']     = 'select_time';
    saveSession($session);

    $dow       = ['日','月','火','水','木','金','土'][date('w', strtotime($date))];
    $dateLabel = date('m月d日（' . $dow . '）', strtotime($date));

    linePush($lineUserId, [
        textMessage("{$dateLabel}ですね✨\nご希望の時間をお選びください！"),
        flexTimeSelect($date, '', $slots),
    ]);
}

/**
 * テキストから日付を解析（「来週土曜」「5月10日」など）
 */
function parseDate(string $text): ?string
{
    // 「YYYY-MM-DD」形式
    if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    // 「M月D日」形式
    if (preg_match('/(\d{1,2})月(\d{1,2})日/', $text, $m)) {
        $year = date('Y');
        $date = sprintf('%04d-%02d-%02d', $year, $m[1], $m[2]);
        // 過去なら来年
        if (strtotime($date) < strtotime('today')) {
            $date = sprintf('%04d-%02d-%02d', $year + 1, $m[1], $m[2]);
        }
        return $date;
    }
    // 「来週の土曜」など → strtotime に任せる
    $keywords = ['今週','来週','再来週','月曜','火曜','水曜','木曜','金曜','土曜','日曜','明日','明後日'];
    foreach ($keywords as $kw) {
        if (str_contains($text, $kw)) {
            $ts = strtotime($text);
            if ($ts && $ts > strtotime('today')) {
                return date('Y-m-d', $ts);
            }
        }
    }
    return null;
}

// ============================================================
// ③時間選択
// ============================================================
function handleTimeSelect(string $lineUserId, string $text, array $customer, array $session): void
{
    $time        = preg_replace('/で$|〜$/', '', $text);
    $data        = $session['temp_data'];
    $durationMin = (int)($data['duration_min'] ?? 30);

    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        // 時間として認識できない → Flex再表示
        $slots = getDateAvailableSlots($data['date'] ?? date('Y-m-d'), $durationMin);
        linePush($lineUserId, [
            textMessage("ご希望の時間をボタンからお選びください😊"),
            flexTimeSelect($data['date'] ?? date('Y-m-d'), '', $slots ?: []),
        ]);
        return;
    }

    // その時間帯に空きがあり、かつメニューに対応できるスタッフを取得
    $availableStaff = getAvailableStaffAt($data['date'], $time, $durationMin);
    $availableStaff = filterStaffByMenu($availableStaff, $data['menu'] ?? '');

    if (empty($availableStaff)) {
        // 空きなし → 日付選択に戻る
        $availableDates = getAvailableDates(60, $durationMin);
        $session['phase'] = 'select_date';
        saveSession($session);
        linePush($lineUserId, [
            textMessage("申し訳ございません😢\nその時間帯は満員です。\n別の日程からお選びください！"),
            flexDateSelect($availableDates),
        ]);
        return;
    }

    $data['time']         = $time;
    $session['temp_data'] = $data;
    $session['phase']     = 'select_staff';
    saveSession($session);

    linePush($lineUserId, [
        textMessage("{$time}〜ですね✨\n担当スタイリストをお選びください！"),
        flexStaffSelect($availableStaff),
    ]);
}

// ============================================================
// ④スタイリスト選択 → クーポン有無で分岐
// ============================================================
function handleStaffSelect(string $lineUserId, string $text, array $customer, array $session): void
{
    $data        = $session['temp_data'];
    $staffName   = preg_replace('/さんで$|で$/', '', $text);
    $durationMin = (int)($data['duration_min'] ?? 30);
    $db          = db();

    if ($text === '指名なし') {
        // その時間に空きがあり、メニューに対応できるスタッフからランダム
        $available = getAvailableStaffAt($data['date'], $data['time'], $durationMin);
        $available = filterStaffByMenu($available, $data['menu'] ?? '');
        if (empty($available)) {
            linePush($lineUserId, [textMessage("申し訳ございません、空きがなくなってしまいました😢\n最初からやり直してください。")]);
            $session['phase'] = 'idle';
            saveSession($session);
            return;
        }
        $picked           = $available[array_rand($available)];
        $data['staff']    = '指名なし';
        $data['staff_id'] = $picked['id'];
    } else {
        $stmt = $db->prepare('SELECT id, name, can_cut, can_color, can_perm, can_treatment FROM staff WHERE name = ? AND is_active = 1');
        $stmt->execute([$staffName]);
        $displayStaff = $stmt->fetch();
        if (!$displayStaff) {
            // 想定外テキスト → スタッフ選択を再表示
            $available = getAvailableStaffAt($data['date'] ?? date('Y-m-d'), $data['time'] ?? '10:00', $durationMin);
            $available = filterStaffByMenu($available, $data['menu'] ?? '');
            linePush($lineUserId, [
                textMessage("スタイリストはボタンからお選びください😊"),
                flexStaffSelect($available),
            ]);
            return;
        }
        $data['staff']    = $displayStaff['name'];
        $data['staff_id'] = $displayStaff['id'];
    }

    $session['temp_data'] = $data;
    $displayName = $data['staff'] === '指名なし' ? 'おまかせ' : $data['staff'] . 'さん';

    // クーポン保有チェック
    $coupons = getCustomerCoupons((int)$customer['id']);

    if (!empty($coupons)) {
        $session['phase'] = 'select_coupon';
        saveSession($session);
        linePush($lineUserId, [
            textMessage("{$displayName}ですね✨"),
            flexCouponSelect($coupons),
        ]);
    } else {
        $session['phase'] = 'confirm_booking';
        saveSession($session);
        linePush($lineUserId, [
            textMessage("{$displayName}ですね✨\n内容をご確認ください😊"),
            flexBookingConfirm($data, $customer['name']),
        ]);
    }
}

// ============================================================
// ⑤クーポン選択
// ============================================================
function handleCouponSelect(string $lineUserId, string $text, array $customer, array $session): void
{
    $data = $session['temp_data'];

    if (in_array($text, ['クーポンなし', 'クーポンを使わない', '使わない'])) {
        unset($data['coupon_id'], $data['coupon_code'], $data['coupon_desc'],
              $data['coupon_discount_type'], $data['coupon_discount'], $data['coupon_rate']);
        $session['temp_data'] = $data;
        $session['phase']     = 'confirm_booking';
        saveSession($session);
        linePush($lineUserId, [
            textMessage("かしこまりました😊\n内容をご確認ください！"),
            flexBookingConfirm($data, $customer['name']),
        ]);
        return;
    }

    if (preg_match('/^クーポン[:：]\s*([A-Za-z0-9]+)/u', $text, $m)) {
        $code = strtoupper($m[1]);
        $stmt = db()->prepare('
            SELECT id, code, description, discount_type, discount, discount_rate
            FROM coupons
            WHERE code = ? AND customer_id = ?
              AND used_at IS NULL
              AND (expired_at IS NULL OR expired_at >= NOW())
        ');
        $stmt->execute([$code, $customer['id']]);
        $coupon = $stmt->fetch();

        if ($coupon) {
            $data['coupon_id']            = $coupon['id'];
            $data['coupon_code']          = $coupon['code'];
            $data['coupon_desc']          = $coupon['description'];
            $data['coupon_discount_type'] = $coupon['discount_type'];
            $data['coupon_discount']      = $coupon['discount'];
            $data['coupon_rate']          = $coupon['discount_rate'];
            $session['temp_data'] = $data;
            $session['phase']     = 'confirm_booking';
            saveSession($session);
            linePush($lineUserId, [
                textMessage("🎫 クーポンを適用しました✨\n内容をご確認ください！"),
                flexBookingConfirm($data, $customer['name']),
            ]);
            return;
        }
    }

    // 想定外テキスト → クーポン選択を再表示
    $coupons = getCustomerCoupons((int)$customer['id']);
    if (empty($coupons)) {
        // クーポンがなくなっていた場合はそのまま確認へ
        $session['phase'] = 'confirm_booking';
        saveSession($session);
        linePush($lineUserId, [
            textMessage("内容をご確認ください😊"),
            flexBookingConfirm($data, $customer['name']),
        ]);
        return;
    }
    linePush($lineUserId, [
        textMessage("クーポンはボタンからお選びください😊"),
        flexCouponSelect($coupons),
    ]);
}

// ============================================================
// ①メニュー選択（フローの最初のステップ）
// ============================================================
function handleMenuSelect(string $lineUserId, string $text, array $customer, array $session): void
{
    $menuName = preg_replace('/で$/', '', $text);
    $db       = db();
    $stmt     = $db->prepare('SELECT id, name, duration_min, price FROM menus WHERE name = ? AND is_active = 1');
    $stmt->execute([$menuName]);
    $menu = $stmt->fetch();

    if (!$menu) {
        // 想定外テキスト → メニューを再表示
        linePush($lineUserId, [
            textMessage("メニューはボタンからお選びください😊"),
            flexMenuSelect(getBookingMenus()),
        ]);
        return;
    }

    $data                 = [];
    $data['menu']         = $menu['name'];
    $data['menu_id']      = $menu['id'];
    $data['duration_min'] = $menu['duration_min'];
    $data['price']        = $menu['price'];
    $session['temp_data'] = $data;
    $session['phase']     = 'select_date';
    saveSession($session);

    $availableDates = getAvailableDates(60, (int)$menu['duration_min']);

    linePush($lineUserId, [
        textMessage("「{$menu['name']}」ですね✨\nご希望の日付をお選びください！"),
        flexDateSelect($availableDates, 0),
    ]);
}

// ============================================================
// 予約確定・変更
// ============================================================
function handleBookingAnswer(string $lineUserId, string $text, array $customer, array $session): void
{
    if (in_array($text, ['確定する', '確定', 'はい', 'OK', 'ok'])) {
        handleBookingConfirm($lineUserId, $customer, $session);
        return;
    }
    if (in_array($text, ['変更する', '変更', 'いいえ'])) {
        $session['phase']     = 'select_menu';
        $session['temp_data'] = [];
        saveSession($session);
        linePush($lineUserId, [
            textMessage("承知しました！改めてメニューをお選びください😊"),
            flexMenuSelect(getBookingMenus()),
        ]);
        return;
    }
    // 想定外テキスト → 確認を再表示
    linePush($lineUserId, [
        textMessage("ボタンからお選びください😊"),
        flexBookingConfirm($session['temp_data'], $customer['name']),
    ]);
}

// ============================================================
// 「ひとつ前に戻る」処理
// ============================================================
function handleGoBack(string $lineUserId, string $text, array $customer, array $session): void
{
    $data        = $session['temp_data'];
    $target      = str_replace('戻る:', '', $text);
    $durationMin = (int)($data['duration_min'] ?? 30);

    switch ($target) {
        case 'メニュー': // 日付選択 → メニュー選択へ（最初から）
            $session['phase']     = 'select_menu';
            $session['temp_data'] = [];
            saveSession($session);
            linePush($lineUserId, [
                textMessage("メニューを選び直してください😊"),
                flexMenuSelect(getBookingMenus()),
            ]);
            break;

        case '日付': // 時間選択 → 日付選択へ
            $session['phase'] = 'select_date';
            unset($data['date'], $data['time'], $data['staff'], $data['staff_id']);
            $session['temp_data'] = $data;
            saveSession($session);
            linePush($lineUserId, [
                textMessage("日程を選び直してください😊"),
                flexDateSelect(getAvailableDates(60, $durationMin)),
            ]);
            break;

        case '時間': // スタッフ選択 → 時間選択へ
            $session['phase'] = 'select_time';
            unset($data['time'], $data['staff'], $data['staff_id']);
            $session['temp_data'] = $data;
            saveSession($session);
            $slots = getDateAvailableSlots($data['date'] ?? date('Y-m-d'), $durationMin);
            linePush($lineUserId, [
                textMessage("時間を選び直してください😊"),
                flexTimeSelect($data['date'] ?? date('Y-m-d'), '', $slots),
            ]);
            break;

        case 'スタッフ': // クーポン選択 → スタッフ選択へ
            $session['phase'] = 'select_staff';
            unset($data['staff'], $data['staff_id'],
                  $data['coupon_id'], $data['coupon_code'], $data['coupon_desc'],
                  $data['coupon_discount_type'], $data['coupon_discount'], $data['coupon_rate']);
            $session['temp_data'] = $data;
            saveSession($session);
            $available = getAvailableStaffAt($data['date'] ?? date('Y-m-d'), $data['time'] ?? '10:00', $durationMin);
            $available = filterStaffByMenu($available, $data['menu'] ?? '');
            linePush($lineUserId, [
                textMessage("担当スタイリストを選び直してください😊"),
                flexStaffSelect($available),
            ]);
            break;

        case 'クーポン': // 確認 → クーポン選択へ（保有なしならスタッフ選択へ）
            unset($data['coupon_id'], $data['coupon_code'], $data['coupon_desc'],
                  $data['coupon_discount_type'], $data['coupon_discount'], $data['coupon_rate']);
            $coupons = getCustomerCoupons((int)$customer['id']);
            if (!empty($coupons)) {
                $session['phase']     = 'select_coupon';
                $session['temp_data'] = $data;
                saveSession($session);
                linePush($lineUserId, [
                    textMessage("クーポンを選び直してください😊"),
                    flexCouponSelect($coupons),
                ]);
            } else {
                $session['phase'] = 'select_staff';
                unset($data['staff'], $data['staff_id']);
                $session['temp_data'] = $data;
                saveSession($session);
                $available = getAvailableStaffAt($data['date'] ?? date('Y-m-d'), $data['time'] ?? '10:00', $durationMin);
                $available = filterStaffByMenu($available, $data['menu'] ?? '');
                linePush($lineUserId, [
                    textMessage("担当スタイリストを選び直してください😊"),
                    flexStaffSelect($available),
                ]);
            }
            break;

        default:
            // 不明な戻る → リセット
            $session['phase'] = 'idle'; $session['temp_data'] = [];
            saveSession($session);
            linePush($lineUserId, [textMessage("最初からやり直します😊
「予約したい」とお送りください！")]);
    }
}

// ============================================================
// 予約仮押さえ
// ============================================================
function handleBookingConfirm(string $lineUserId, array $customer, array $session): void
{
    $data = $session['temp_data'];
    $db   = db();

    $startAt     = ($data['date'] ?? date('Y-m-d')) . ' ' . ($data['time'] ?? '10:00') . ':00';
    $durationMin = $data['duration_min'] ?? 60;
    $endAt       = date('Y-m-d H:i:s', strtotime($startAt) + $durationMin * 60);

    // クーポン利用予定を備考へ記録（使用処理はご来店時のQR照合で実施）
    $note = null;
    if (!empty($data['coupon_code'])) {
        $note = 'クーポン利用予定：' . ($data['coupon_desc'] ?? 'クーポン') . '（' . $data['coupon_code'] . '）';
    }

    $stmt = $db->prepare('
        INSERT INTO reservations (customer_id, staff_id, menu_id, start_at, end_at, status, menu_snapshot, note)
        VALUES (?, ?, ?, ?, ?, "pending", ?, ?)
    ');
    $stmt->execute([
        $customer['id'],
        $data['staff_id'] ?? null,
        $data['menu_id']  ?? null,
        $startAt, $endAt,
        json_encode($data, JSON_UNESCAPED_UNICODE),
        $note,
    ]);

    // リマインドキューに登録（前日18時）
    $reservationDate = date('Y-m-d', strtotime($startAt));
    $sendAt          = $reservationDate . ' 18:00:00';
    $prevDay         = date('Y-m-d', strtotime($reservationDate . ' -1 day')) . ' 18:00:00';

    // 前日18時が未来であれば登録
    if (strtotime($prevDay) > time()) {
        $sendAt = $prevDay;
    } else {
        $sendAt = null; // 前日が過去の場合はリマインドなし
    }

    if ($sendAt) {
        $newResId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO reminders (reservation_id, send_at) VALUES (?,?)')->execute([$newResId, $sendAt]);
        writeLog('INFO', 'Reminder registered', ['send_at' => $sendAt]);
    }

    $session['phase']     = 'idle';
    $session['temp_data'] = [];
    saveSession($session);

    writeLog('INFO', 'Booking pending', ['customer_id' => $customer['id'], 'start_at' => $startAt]);

    $couponLine = !empty($data['coupon_code'])
        ? "🎫 クーポンはご来店時のお会計でご提示ください\n\n"
        : "";

    linePush($lineUserId, [textMessage(
        "仮予約を受け付けました✨\n\n" .
        $couponLine .
        "スタッフが確認次第、確定のご連絡をLINEでお送りします。\n" .
        "通常1時間以内にご連絡いたします😊\n\n" .
        "お待たせしてしまう場合はご了承ください🙏"
    )]);
}
