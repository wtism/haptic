<?php
// ============================================================
// lib/session.php  - LINE会話セッション管理
// ============================================================

require_once __DIR__ . '/../config/db.php';

/**
 * セッション取得（なければ初期化して返す）
 */
function getSession(string $lineUserId): array
{
    $db   = db();
    $stmt = $db->prepare('SELECT * FROM line_sessions WHERE line_user_id = ?');
    $stmt->execute([$lineUserId]);
    $row  = $stmt->fetch();

    if (!$row) {
        return [
            'line_user_id' => $lineUserId,
            'messages'     => [],
            'phase'        => 'idle',
            'temp_data'    => [],
        ];
    }

    return [
        'line_user_id' => $lineUserId,
        'messages'     => json_decode($row['messages_json'], true) ?? [],
        'phase'        => $row['phase'],
        'temp_data'    => json_decode($row['temp_data'], true) ?? [],
    ];
}

/**
 * セッション保存（upsert）
 */
function saveSession(array $session): void
{
    $db = db();

    // 会話履歴は最新20ターンのみ保持
    $messages = $session['messages'] ?? [];
    if (count($messages) > 40) { // user+assistantで40件 = 20ターン
        $messages = array_slice($messages, -40);
    }

    $stmt = $db->prepare('
        INSERT INTO line_sessions (line_user_id, messages_json, phase, temp_data)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            messages_json = VALUES(messages_json),
            phase         = VALUES(phase),
            temp_data     = VALUES(temp_data),
            updated_at    = CURRENT_TIMESTAMP
    ');

    $stmt->execute([
        $session['line_user_id'],
        json_encode($messages, JSON_UNESCAPED_UNICODE),
        $session['phase'],
        json_encode($session['temp_data'] ?? [], JSON_UNESCAPED_UNICODE),
    ]);
}

/**
 * セッションリセット
 */
function resetSession(string $lineUserId): void
{
    $db   = db();
    $stmt = $db->prepare('
        UPDATE line_sessions
        SET messages_json = "[]", phase = "idle", temp_data = "{}"
        WHERE line_user_id = ?
    ');
    $stmt->execute([$lineUserId]);
}

/**
 * 会話にメッセージ追加
 */
function addMessage(array &$session, string $role, string $content): void
{
    $session['messages'][] = [
        'role'    => $role,
        'content' => $content,
    ];
}
