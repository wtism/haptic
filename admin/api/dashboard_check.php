<?php
// ============================================================
// admin/api/dashboard_check.php - ダッシュボード自動更新チェック
// GET ?date=YYYY-MM-DD
// 指定日の予約に変更（新規・更新・キャンセル含む）があったかを軽量に返す
// ============================================================
require_once dirname(__DIR__) . '/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$db = db();

// 表示中の日付の予約（タイムテーブル用）
$stmt = $db->prepare("
    SELECT COUNT(*) AS cnt,
           COALESCE(MAX(id), 0) AS max_id,
           COALESCE(MAX(updated_at), '') AS latest
    FROM reservations
    WHERE DATE(start_at) = ?
");
$stmt->execute([$date]);
$dayRow = $stmt->fetch();

// 未承認の仮予約（日付に関係なく一覧表示されるため、こちらも別途監視）
$pendRow = $db->query("
    SELECT COUNT(*) AS cnt,
           COALESCE(MAX(id), 0) AS max_id,
           COALESCE(MAX(updated_at), '') AS latest
    FROM reservations
    WHERE status = 'pending'
")->fetch();

$sig = implode('_', [
    $dayRow['cnt'], $dayRow['max_id'], $dayRow['latest'],
    $pendRow['cnt'], $pendRow['max_id'], $pendRow['latest'],
]);

echo json_encode(['success' => true, 'sig' => $sig], JSON_UNESCAPED_UNICODE);
