<?php
// ============================================================
// api/liff/register.php - 顧客登録（LIFF内フォーム）
// POST {line_uid, name, phone?, gender?, birthdate?, line_name?}
// ============================================================
require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__, 2) . '/lib/customer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_err(405, '許可されていないメソッドです');

$body    = json_body();
$lineUid = trim($body['line_uid'] ?? '');
$name    = mb_substr(trim($body['name'] ?? ''), 0, 30);

if (!$lineUid || !preg_match('/^U[0-9a-f]{32}$/i', $lineUid)) json_err(400, 'LINEユーザーIDが不正です');
if ($name === '') json_err(422, 'お名前を入力してください');

createCustomer($lineUid, mb_substr(trim($body['line_name'] ?? ''), 0, 50));

$update = ['name' => $name];

$phone = preg_replace('/[^0-9\-+]/', '', $body['phone'] ?? '');
if ($phone !== '') $update['phone'] = mb_substr($phone, 0, 20);

$genderMap = ['female' => 'female', 'male' => 'male', 'other' => 'other'];
if (isset($genderMap[$body['gender'] ?? ''])) $update['gender'] = $genderMap[$body['gender']];

$bd = trim($body['birthdate'] ?? '');
if ($bd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) && strtotime($bd) < time()) {
    $update['birthdate'] = $bd;
}

updateCustomer($lineUid, $update);

json_ok(['name' => $name]);
