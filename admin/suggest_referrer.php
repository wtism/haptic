<?php
/**
 * 紹介者サジェスト API
 * GET /admin/api/suggest_referrer.php?q=田中&exclude=123
 * Response: JSON array [{id, name, furigana, phone}]
 */
require_once dirname(__DIR__) . '/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$q       = trim($_GET['q'] ?? '');
$exclude = (int)($_GET['exclude'] ?? 0);

if (mb_strlen($q) < 1) {
    echo json_encode([]);
    exit;
}

$db = db();
$stmt = $db->prepare("
    SELECT id,
           COALESCE(name, line_name, '名前未登録') AS name,
           furigana,
           phone
    FROM customers
    WHERE (name LIKE :q OR furigana LIKE :q OR phone LIKE :q OR line_name LIKE :q)
      AND id != :exclude
    ORDER BY name
    LIMIT 8
");
$stmt->execute([':q' => "%{$q}%", ':exclude' => $exclude]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
