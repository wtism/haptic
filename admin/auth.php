<?php
// admin/auth.php  - 認証（スタッフログイン対応版）
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';

session_start();

function requireLogin(): void
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . adminUrl('index.php'));
        exit;
    }
}

function requireSuperAdmin(): void
{
    requireLogin();
    if (empty($_SESSION['is_super_admin']) && empty($_SESSION['is_admin'])) {
        header('Location: ' . adminUrl('dashboard.php'));
        exit;
    }
}

function adminLogin(string $loginId, string $password): bool
{
    $db = db();

    // まずadmin_usersテーブルで確認（スーパーユーザー）
    $stmt = $db->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = ? AND is_active = 1');
    $stmt->execute([$loginId]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['admin_id']       = 'admin_' . $user['id'];
        $_SESSION['admin_name']     = $user['username'];
        $_SESSION['admin_role']     = 'スーパー管理者';
        $_SESSION['is_super_admin'] = true;
        $_SESSION['is_admin']       = true;
        $_SESSION['staff_id']       = null;
        return true;
    }

    // staffテーブルで確認（スタッフログイン）
    $stmt = $db->prepare('
        SELECT s.*, r.name AS role_name
        FROM staff s
        LEFT JOIN staff_roles r ON s.role_id = r.id
        WHERE s.login_id = ? AND s.is_active = 1 AND s.password_hash IS NOT NULL
    ');
    $stmt->execute([$loginId]);
    $staff = $stmt->fetch();
    if ($staff && password_verify($password, $staff['password_hash'])) {
        $_SESSION['admin_id']       = 'staff_' . $staff['id'];
        $_SESSION['admin_name']     = $staff['name'];
        $_SESSION['admin_role']     = $staff['role_name'] ?? 'スタッフ';
        $_SESSION['is_super_admin'] = false;
        $_SESSION['is_admin']       = (bool)$staff['is_admin'];
        $_SESSION['staff_id']       = $staff['id'];
        return true;
    }

    return false;
}

function adminLogout(): void
{
    session_destroy();
    header('Location: ' . adminUrl('index.php'));
    exit;
}

function currentAdminId(): int
{
    // セッションIDから数値部分を取得
    $id = $_SESSION['admin_id'] ?? '0';
    return (int)preg_replace('/[^0-9]/', '', $id);
}

function currentAdminName(): string
{
    return $_SESSION['admin_name'] ?? '';
}

function isSuperAdmin(): bool
{
    return !empty($_SESSION['is_super_admin']);
}

function isAdmin(): bool
{
    return !empty($_SESSION['is_admin']);
}

function adminUrl(string $page): string
{
    return '/admin/' . $page;
}

function h(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

function csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
}

// 監査ログ記録
function auditLog(string $action, string $targetType, int $targetId, string $detail = ''): void
{
    try {
        db()->prepare('
            INSERT INTO audit_logs (admin_id, admin_name, action, target_type, target_id, detail)
            VALUES (?,?,?,?,?,?)
        ')->execute([currentAdminId(), currentAdminName(), $action, $targetType, $targetId, $detail]);
    } catch (Throwable $e) {
        // ログ失敗は無視
    }
}
