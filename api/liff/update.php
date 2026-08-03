<?php
// ============================================================
// api/liff/update.php - 予約変更（マイページから）
// POST {line_uid, reservation_id, menu_id, date, time, staff_id(int|'any')}
// 変更後はstatus='pending'に戻し、スタッフの再確認を待つ
// ============================================================
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/lib/line.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err(405, '許可されていないメソッドです');

$body    = json_body();
$lineUid = trim($body['line_uid'] ?? '');
$rid     = (int)($body['reservation_id'] ?? 0);
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
if (!$rid) json_err(422, '予約IDが不正です');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_err(422, '日付の形式が不正です');
if (!preg_match('/^\d{2}:\d{2}$/', $time))       json_err(422, '時間の形式が不正です');
if (empty($menuIds)) json_err(422, 'メニューを選択してください');
if (count($menuIds) > 5) json_err(422, 'メニューは5つまでお選びいただけます');

$db = db();

// ── 本人確認 ──
$stmt = $db->prepare('SELECT * FROM customers WHERE line_user_id = ?');
$stmt->execute([$lineUid]);
$customer = $stmt->fetch();
if (!$customer) json_err(404, 'お客様情報が見つかりません');

// ── 対象予約の確認（自分の予約のみ・開始前のみ） ──
$stmt = $db->prepare('SELECT * FROM reservations WHERE id = ? AND customer_id = ?');
$stmt->execute([$rid, $customer['id']]);
$rsv = $stmt->fetch();
if (!$rsv) json_err(404, 'ご予約が見つかりません');
if (!in_array($rsv['status'], ['pending', 'confirmed'])) json_err(409, 'このご予約は変更できません');
if (strtotime($rsv['start_at']) <= time()) {
    json_err(409, '開始時刻を過ぎたご予約はこちらから変更できません。お手数ですがLINEトークでご連絡ください🙏');
}

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
$menu        = $menuList[0];

$startAt = "{$date} {$time}:00";
$endAt   = date('Y-m-d H:i:s', strtotime($startAt) + $durationMin * 60);

if (strtotime($startAt) <= time()) json_err(422, '過去の日時は指定できません');
if (isRegularHoliday($date) || isShopHoliday($date)) json_err(409, 'その日は休業日です');

// ── スタッフ割り当て（自分の予約を除外・全メニュー対応スタッフのみ） ──
$isNoPreference = ($staffId === 'any' || $staffId === '' || $staffId === null);
$availableStaff = getAvailableStaffAt($date, $time, $durationMin, $rid);
foreach ($menuNames as $mn) {
    $availableStaff = filterStaffByMenu($availableStaff, $mn);
}

if ($isNoPreference) {
    if (empty($availableStaff)) json_err(409, '申し訳ございません、その時間帯は他のお客様のご予約と重なっています。別の日時をお選びください🙏');
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
    if (!$ok) json_err(409, '申し訳ございません、その時間帯はスタイリストの予約が他のお客様と重なっています。別の日時をお選びください🙏');
    $staffLabel = $staffName;
}

// ── 顧客自身の二重予約チェック（変更対象は除外） ──
$dup = $db->prepare("
    SELECT id FROM reservations
    WHERE customer_id = ? AND id <> ? AND status NOT IN ('cancelled')
      AND start_at < ? AND end_at > ?
");
$dup->execute([$customer['id'], $rid, $endAt, $startAt]);
if ($dup->fetch()) json_err(409, 'その時間帯にはすでに別のご予約があります。');

// ── スナップショット再構築（クーポン利用予定は引き継ぐ） ──
$oldSnap  = json_decode($rsv['menu_snapshot'] ?? '', true) ?: [];
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
    'edited_at'    => date('Y-m-d H:i'),
];
foreach (['coupon_id','coupon_code','coupon_desc','coupon_discount_type','coupon_discount','coupon_rate'] as $k) {
    if (isset($oldSnap[$k])) $snapshot[$k] = $oldSnap[$k];
}

$oldLabel = date('n/j H:i', strtotime($rsv['start_at']));
$newLabel = date('n/j H:i', strtotime($startAt));
$note = trim(($rsv['note'] ? $rsv['note'] . "\n" : '') .
    "お客様がLINEから変更（" . date('Y-m-d H:i') . "）：{$oldLabel} → {$newLabel}");

// ── 更新 ──
$db->beginTransaction();
try {
    $db->prepare("
        UPDATE reservations
        SET staff_id = ?, menu_id = ?, start_at = ?, end_at = ?,
            status = 'pending', menu_snapshot = ?, note = ?
        WHERE id = ?
    ")->execute([
        $staffId, $menu['id'], $startAt, $endAt,
        json_encode($snapshot, JSON_UNESCAPED_UNICODE), $note, $rid,
    ]);

    // リマインドを取り直し（前日18時15分）
    $db->prepare('DELETE FROM reminders WHERE reservation_id = ? AND sent_flag = 0')->execute([$rid]);
    $prevDay = date('Y-m-d', strtotime($date . ' -1 day')) . ' 18:15:00';
    if (strtotime($prevDay) > time()) {
        $db->prepare('INSERT INTO reminders (reservation_id, send_at) VALUES (?,?)')->execute([$rid, $prevDay]);
    }

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    json_err(500, '予約の変更に失敗しました');
}

// ── LINE通知（失敗しても処理は成立） ──
$dow     = ['日','月','火','水','木','金','土'][date('w', strtotime($date))];
$dateStr = date('n月j日', strtotime($date)) . "（{$dow}）";
$timeStr = $time . '〜' . date('H:i', strtotime($endAt));
try {
    linePush($lineUid, [textMessage(
        "🔁 ご予約の変更を受け付けました✨\n\n" .
        "【変更後のご予約内容】\n" .
        "📅 {$dateStr} {$timeStr}\n" .
        "✂️ {$menuLabel}\n" .
        "👤 担当：{$staffLabel}\n\n" .
        "スタッフが確認次第、確定のご連絡をお送りします😊"
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
]);
