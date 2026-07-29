<?php
// ============================================================
// coupon/check.php  - クーポンQRスキャン照合ページ
// スタッフがスキャンして使用済みにするページ（ログイン不要）
// URL: https://haptic.irodori.tokyo/coupon/check.php?code=XXXXXXXX&t=TOKEN
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';

$code  = strtoupper(trim($_GET['code'] ?? ''));
$token = $_GET['t'] ?? '';
$msg   = '';
$msgType = 'success';
$coupon  = null;
$customer = null;

if (!$code) {
    $msg = 'クーポンコードが指定されていません';
    $msgType = 'danger';
} else {
    $db   = db();
    $stmt = $db->prepare('
        SELECT cp.*, c.name AS customer_name, c.phone
        FROM coupons cp
        JOIN customers c ON cp.customer_id = c.id
        WHERE cp.code = ?
    ');
    $stmt->execute([$code]);
    $coupon = $stmt->fetch();

    // トークン検証（code + SECRET のハッシュ）
    $expectedToken = substr(hash_hmac('sha256', $code, env('CRON_SECRET', 'irodori_cron_2024')), 0, 16);
    if ($token !== $expectedToken) {
        $msg = '無効なURLです';
        $msgType = 'danger';
        $coupon = null;
    }
}

// 使用済み処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $coupon && !$coupon['used_at']) {
    $reservationId = $_POST['reservation_id'] ? (int)$_POST['reservation_id'] : null;
    $db = db();
    $db->prepare('UPDATE coupons SET used_at=NOW(), used_reservation_id=? WHERE id=?')
       ->execute([$reservationId, $coupon['id']]);
    $msg = '✅ クーポンを使用済みにしました';
    $coupon['used_at'] = date('Y-m-d H:i:s');
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>クーポン照合</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Hiragino Sans', sans-serif; background: #f5f7fa; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.card { background: #fff; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); width: 100%; max-width: 400px; overflow: hidden; }
.header { background: #6B9E8A; padding: 24px 20px; text-align: center; color: #fff; }
.header h1 { font-size: 1.2em; margin-bottom: 4px; }
.header p { font-size: 0.85em; opacity: 0.9; }
.body { padding: 24px 20px; }
.alert { padding: 14px 16px; border-radius: 10px; margin-bottom: 16px; font-size: 0.95em; font-weight: 600; text-align: center; }
.alert-success { background: #d4edda; color: #155724; }
.alert-danger  { background: #f8d7da; color: #721c24; }
.alert-warning { background: #fff3cd; color: #856404; }
.info-table { width: 100%; margin-bottom: 20px; }
.info-table td { padding: 8px 0; font-size: 0.9em; border-bottom: 1px solid #f0f0f0; }
.info-table td:first-child { color: #888; width: 80px; }
.info-table td:last-child { font-weight: 600; }
.code-badge { font-family: monospace; font-size: 1.3em; letter-spacing: 3px; background: #f0f0f0; padding: 8px 16px; border-radius: 8px; display: inline-block; }
.btn { width: 100%; padding: 14px; border: none; border-radius: 10px; font-size: 1em; font-weight: bold; cursor: pointer; }
.btn-primary { background: #6B9E8A; color: #fff; }
.btn-danger  { background: #e74c3c; color: #fff; }
.used-badge { display: inline-block; background: #e2e3e5; color: #383d41; padding: 6px 16px; border-radius: 20px; font-size: 0.9em; font-weight: 600; }
.discount { font-size: 2em; font-weight: bold; color: #6B9E8A; text-align: center; margin: 16px 0; }
</style>
</head>
<body>
<div class="card">
    <div class="header">
        <h1>🎫 クーポン照合</h1>
        <p>スタッフ用照合画面</p>
    </div>
    <div class="body">
        <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div>
        <?php endif; ?>

        <?php if (!$coupon): ?>
        <p style="text-align:center;color:#888;">クーポンが見つかりません</p>

        <?php elseif ($coupon['used_at']): ?>
        <div class="alert alert-warning">⚠️ このクーポンは使用済みです</div>
        <table class="info-table">
            <tr><td>お客様</td><td><?= h($coupon['customer_name']) ?>様</td></tr>
            <tr><td>コード</td><td><span class="code-badge"><?= h($coupon['code']) ?></span></td></tr>
            <tr><td>内容</td><td><?= h($coupon['description']) ?></td></tr>
            <tr><td>割引額</td><td>¥<?= number_format($coupon['discount']) ?></td></tr>
            <tr><td>使用日</td><td><?= h(date('Y年m月d日', strtotime($coupon['used_at']))) ?></td></tr>
        </table>
        <div style="text-align:center;"><span class="used-badge">✓ 使用済み</span></div>

        <?php elseif ($coupon['expired_at'] && strtotime($coupon['expired_at']) < time()): ?>
        <div class="alert alert-danger">❌ このクーポンは有効期限切れです</div>
        <table class="info-table">
            <tr><td>お客様</td><td><?= h($coupon['customer_name']) ?>様</td></tr>
            <tr><td>有効期限</td><td><?= h(date('Y年m月d日', strtotime($coupon['expired_at']))) ?></td></tr>
        </table>

        <?php else: ?>
        <div class="alert alert-success">✅ 有効なクーポンです</div>
        <table class="info-table">
            <tr><td>お客様</td><td><?= h($coupon['customer_name']) ?>様</td></tr>
            <tr><td>コード</td><td><span class="code-badge"><?= h($coupon['code']) ?></span></td></tr>
            <tr><td>内容</td><td><?= h($coupon['description']) ?></td></tr>
            <tr><td>有効期限</td><td><?= $coupon['expired_at'] ? h(date('Y年m月d日', strtotime($coupon['expired_at']))) : '無期限' ?></td></tr>
        </table>
        <div class="discount">¥<?= number_format($coupon['discount']) ?> OFF</div>
        <form method="post">
            <input type="hidden" name="reservation_id" value="">
            <button class="btn btn-primary" type="submit" onclick="return confirm('このクーポンを使用済みにしますか？')">
                ✅ 使用済みにする
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
