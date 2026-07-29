<?php
// admin/index.php  - ログイン
require_once __DIR__ . '/auth.php';

if (isset($_GET['logout'])) { adminLogout(); }
if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . adminUrl('dashboard.php')); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (adminLogin($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        header('Location: ' . adminUrl('dashboard.php')); exit;
    }
    $error = 'ユーザー名またはパスワードが違います';
}

// 店舗名（ログイン画面のタイトル表示用）
try {
    $shopName = db()->query('SELECT shop_name FROM shop_settings WHERE id=1')->fetchColumn() ?: '予約管理';
} catch (Throwable $e) {
    $shopName = '予約管理';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ログイン | <?= h($shopName) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Hiragino Sans', sans-serif; background: #f5f7fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.login-box { background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); padding: 40px; width: 100%; max-width: 360px; margin: 0 16px; }
h1 { font-size: 1.3em; color: #2c3e50; margin-bottom: 6px; text-align: center; }
.subtitle { font-size: 0.85em; color: #888; text-align: center; margin-bottom: 28px; }
label { display: block; font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 0.95em; margin-bottom: 16px; }
input:focus { outline: none; border-color: #6B9E8A; }
.btn { width: 100%; padding: 11px; background: #6B9E8A; color: #fff; border: none; border-radius: 6px; font-size: 1em; font-weight: 600; cursor: pointer; }
.btn:hover { background: #5a8a77; }
.error { background: #f8d7da; color: #721c24; padding: 10px 14px; border-radius: 6px; font-size: 0.88em; margin-bottom: 16px; }
.brand { color: #6B9E8A; font-weight: bold; }
</style>
</head>
<body>
<div class="login-box">
    <h1>📋 予約管理システム</h1>
    <p class="subtitle">管理者ログイン</p>
    <?php if ($error): ?>
    <div class="error"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
        <label>ユーザー名</label>
        <input type="text" name="username" autofocus autocomplete="username">
        <label>パスワード</label>
        <input type="password" name="password" autocomplete="current-password">
        <button class="btn" type="submit">ログイン</button>
    </form>
</div>
</body>
</html>
