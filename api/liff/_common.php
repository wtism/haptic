<?php
// ============================================================
// api/liff/_common.php - LIFF API共通ブートストラップ
// ============================================================
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/lib/availability.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function json_ok(array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_body(): array
{
    return json_decode(file_get_contents('php://input'), true) ?: [];
}
