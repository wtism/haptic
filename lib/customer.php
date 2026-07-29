<?php
// ============================================================
// lib/customer.php  - 顧客管理
// ============================================================

require_once __DIR__ . '/../config/db.php';

/**
 * LINE IDで顧客取得
 */
function getCustomer(string $lineUserId): ?array
{
    $db   = db();
    $stmt = $db->prepare('SELECT * FROM customers WHERE line_user_id = ?');
    $stmt->execute([$lineUserId]);
    return $stmt->fetch() ?: null;
}

/**
 * 顧客新規登録
 */
function createCustomer(string $lineUserId, string $lineName = ''): array
{
    $db   = db();
    $stmt = $db->prepare('
        INSERT INTO customers (line_user_id, line_name, language)
        VALUES (?, ?, "ja")
        ON DUPLICATE KEY UPDATE line_name = VALUES(line_name)
    ');
    $stmt->execute([$lineUserId, $lineName]);

    return getCustomer($lineUserId);
}

/**
 * 顧客情報更新
 */
function updateCustomer(string $lineUserId, array $data): void
{
    $db      = db();
    $allowed = ['name', 'phone', 'gender', 'birthdate', 'language'];
    $sets    = [];
    $params  = [];

    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $sets[]   = "{$field} = ?";
            $params[] = $data[$field];
        }
    }

    if (empty($sets)) return;

    $params[] = $lineUserId;
    $sql      = 'UPDATE customers SET ' . implode(', ', $sets) . ' WHERE line_user_id = ?';
    $db->prepare($sql)->execute($params);
}

/**
 * 顧客の過去予約履歴（直近3件）
 */
function getCustomerHistory(int $customerId): array
{
    $db   = db();
    $stmt = $db->prepare('
        SELECT r.start_at, r.status, m.name AS menu_name, s.name AS staff_name
        FROM reservations r
        LEFT JOIN menus m ON r.menu_id = m.id
        LEFT JOIN staff s ON r.staff_id = s.id
        WHERE r.customer_id = ?
          AND r.status IN ("completed", "confirmed")
        ORDER BY r.start_at DESC
        LIMIT 3
    ');
    $stmt->execute([$customerId]);
    return $stmt->fetchAll();
}
