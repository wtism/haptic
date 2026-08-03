<?php
// ============================================================
// api/liff/reserve.php - 仮予約作成
// POST {line_uid, menu_ids[]（またはmenu_id）, date, time, staff_id(int|'any'), coupon_id?}
// 複数メニュー対応：所要時間・料金は合算、先頭メニューをmenu_idに保存
// ============================================================
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/lib/line.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err(405, '許可されていないメソッドです');

$body    = json_body();
$lineUid = trim($body['line_uid'] ?? '');
$date    = $body['date'] ?? '';
$time    = $body['time'] ?? '';
$staffId = $body['staff_id'] ?? null;

// menu_ids（複数）またはmenu_id（単数・後方互換）
$menuIds = [];
if (!empty($body['menu_ids']) && is_array($body['menu_ids'])) {
    $menuIds = array_values(array_unique(array_filter(array_map('intval', $body['menu_ids']))));
} elseif (!empty($body['menu_id'])) {
    $menuIds = [(int)$body['menu_id']];
}

if (!$lineUid || !preg_match('/^U[0-9a-f]{32}$/i', $lineUid)) json_err(400, 'LINEユーザーIDが不正です');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_err(422, '日付の形式が不正です');
if (!preg_match('/^\d{2}:\d{2}$/', $time))       json_err(422, '時間の形式が不正です');
if (empty($menuIds)) json_err(422, 'メニューを選択してください');
if (count($menuIds) > 5) json_err(422, 'メニューは5つまでお選びいただけます');

$db = db();

// ── 顧客確認 ──
$stmt = $db->prepare('SELECT * FROM customers WHERE line_user_id = ?');
$stmt->execute([$lineUid]);
$customer = $stmt->fetch();
if (!$customer || empty($customer['name'])) json_err(404, 'お客様登録が必要です');

// ── メニュー確認（選択順を維持して取得） ──
$in   = implode(',', array_fill(0, count($menuIds), '?'));
$stmt = $db->prepare("SELECT id, name, duration_min, price FROM menus WHERE id IN ({$in}) AND is_active = 1");
$stmt->execute($menuIds);
$byId = [];
foreach ($stmt->fetchAll() as $row) $byId[(int)$row['id']] = $row;
if (count($byId) !== count($menuIds)) json_err(422, 'メニューが見つかりません');
$menuList = array_map(fn($id) => $byId[$id], $menuIds);

$durationMin = array_sum(array_map(fn($m) => (int)$m['duration_min'], $menuList));
$totalPrice  = array_sum(array_map(fn($m) => (int)$m['price'], $menuList));
$menuNames   = array_map(fn($m) => $m['name'], $menuList);
$menuLabel   = implode('・', $menuNames);
$menu        = $menuList[0]; // 先頭メニュー（reservations.menu_idに保存）

$startAt = "{$date} {$time}:00";
$endAt   = date('Y-m-d H:i:s', strtotime($startAt) + $durationMin * 60);

if (strtotime($startAt) <= time()) json_err(422, '過去の日時は指定できません');
if (isRegularHoliday($date) || isShopHoliday($date)) json_err(409, 'その日は休業日です');

// ── スタッフ割り当て（全メニューに対応できるスタッフのみ） ──
$isNoPreference = ($staffId === 'any' || $staffId === '' || $staffId === null);
$availableStaff = getAvailableStaffAt($date, $time, $durationMin);
foreach ($menuNames as $mn) {
    $availableStaff = filterStaffByMenu($availableStaff, $mn);
}

if ($isNoPreference) {
    if (empty($availableStaff)) json_err(409, '申し訳ございません、その時間帯は満席です。別の日時をお選びください。');
    $picked     = $availableStaff[array_rand($availableStaff)];
    $staffId    = (int)$picked['id'];
    $staffName  = $picked['name'];
    $staffLabel = 'おまかせ';
} else {
    $staffId = (int)$staffId;
    $ok = false;
    foreach ($availableStaff as $s) {
        if ((int)$s['id'] === $staffId) { $ok = true; $staffName = $s['name']; break; }
    }
    if (!$ok) json_err(409, '申し訳ございません、そのスタイリストの枠が埋まってしまいました。別の時間をお選びください。');
    $staffLabel = $staffName;
}

// ── 顧客の二重予約チェック ──
$dup = $db->prepare("
    SELECT id FROM reservations
    WHERE customer_id = ? AND status NOT IN ('cancelled')
      AND start_at < ? AND end_at > ?
");
$dup->execute([$customer['id'], $endAt, $startAt]);
if ($dup->fetch()) json_err(409, 'その時間帯はすでにご予約済みです。');

// ── クーポン確認（使用処理はご来店時） ──
$coupon = null;
if (!empty($body['coupon_id'])) {
    $stmt = $db->prepare('
        SELECT id, code, description, discount_type, discount, discount_rate
        FROM coupons
        WHERE id = ? AND customer_id = ? AND used_at IS NULL
          AND (expired_at IS NULL OR expired_at >= NOW())
    ');
    $stmt->execute([(int)$body['coupon_id'], $customer['id']]);
    $coupon = $stmt->fetch() ?: null;
}

// ── 予約作成 ──
$snapshot = [
    'menu'         => $menuLabel,
    'menu_id'      => (int)$menu['id'],
    'menu_ids'     => array_map(fn($m) => (int)$m['id'], $menuList),
    'menus'        => array_map(fn($m) => [
        'id' => (int)$m['id'], 'name' => $m['name'],
        'price' => (int)$m['price'], 'duration_min' => (int)$m['duration_min'],
    ], $menuList),
    'duration_min' => $durationMin,
    'price'        => $totalPrice,
    'date'         => $date,
    'time'         => $time,
    'staff'        => $staffLabel === 'おまかせ' ? '指名なし' : $staffName,
    'staff_id'     => $staffId,
    'source'       => 'liff',
];
$note = null;
if ($coupon) {
    $snapshot['coupon_id']            = (int)$coupon['id'];
    $snapshot['coupon_code']          = $coupon['code'];
    $snapshot['coupon_desc']          = $coupon['description'];
    $snapshot['coupon_discount_type'] = $coupon['discount_type'];
    $snapshot['coupon_discount']      = (int)$coupon['discount'];
    $snapshot['coupon_rate']          = $coupon['discount_rate'] !== null ? (int)$coupon['discount_rate'] : null;
    $note = 'クーポン利用予定：' . $coupon['description'] . '（' . $coupon['code'] . '）';
}

$db->beginTransaction();
try {
    $db->prepare('
        INSERT INTO reservations (customer_id, staff_id, menu_id, start_at, end_at, status, menu_snapshot, note)
        VALUES (?, ?, ?, ?, ?, "pending", ?, ?)
    ')->execute([
        $customer['id'], $staffId, $menu['id'], $startAt, $endAt,
        json_encode($snapshot, JSON_UNESCAPED_UNICODE), $note,
    ]);
    $rid = (int)$db->lastInsertId();

    // 前日18時15分リマインド
    $prevDay = date('Y-m-d', strtotime($date . ' -1 day')) . ' 18:15:00';
    if (strtotime($prevDay) > time()) {
        $db->prepare('INSERT INTO reminders (reservation_id, send_at) VALUES (?,?)')->execute([$rid, $prevDay]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    json_err(500, '予約の作成に失敗しました');
}

// ── LINE通知（失敗しても予約は成立） ──
$dow      = ['日','月','火','水','木','金','土'][date('w', strtotime($date))];
$dateStr  = date('n月j日', strtotime($date)) . "（{$dow}）";
$timeStr  = $time . '〜' . date('H:i', strtotime($endAt));
$couponLine = $coupon ? "🎫 {$coupon['description']}（ご来店時にご提示ください）\n" : '';

try {
    linePush($lineUid, [textMessage(
        "📋 仮予約を受け付けました✨\n\n" .
        "【ご予約内容】\n" .
        "📅 {$dateStr} {$timeStr}\n" .
        "✂️ {$menuLabel}\n" .
        "👤 担当：{$staffLabel}\n" .
        $couponLine . "\n" .
        "スタッフが確認次第、確定のご連絡をお送りします😊\n" .
        "通常1時間以内にご連絡いたします。"
    )]);
} catch (Throwable $e) {
    // 通知失敗は無視
}

json_ok([
    'reservation_id' => $rid,
    'date_label'     => $dateStr,
    'time_label'     => $timeStr,
    'menu'           => $menuLabel,
    'staff'          => $staffLabel,
], 201);
