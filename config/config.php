<?php
// ============================================================
// config.php  - 環境設定読み込み
// 配置: /home/mogans/www/haptic.irodori.tokyo/config/config.php
// ============================================================

// .envはWebルート外から読む。
// サイト別の .env.<ドメイン> を優先し、無ければ共有 .env にフォールバック。
$docRoot   = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__);
$parentDir = dirname($docRoot);
$siteEnv   = $parentDir . '/.env.' . basename($docRoot); // 例: /home/mogans/www/.env.haptic.irodori.tokyo
$sharedEnv = $parentDir . '/.env';                       // 共有: /home/mogans/www/.env

if (file_exists($siteEnv)) {
    $envPath = $siteEnv;
} elseif (file_exists($sharedEnv)) {
    $envPath = $sharedEnv;
} else {
    // フォールバック: 同階層の.env.local（ローカル開発用）
    $envPath = __DIR__ . '/.env.local';
}

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
        putenv(trim($key) . '=' . trim($val));
    }
}

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}
