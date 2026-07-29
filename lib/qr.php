<?php
// ============================================================
// lib/qr.php  - QRコード生成ヘルパー
// phpqrcodeライブラリのラッパー
// ============================================================

function generateQrCodeBase64(string $text, int $size = 200): string
{
    $libPath  = '/home/mogans/www/haptic.irodori.tokyo/lib/phpqrcode/qrlib.php';
    $cacheDir = '/home/mogans/www/haptic.irodori.tokyo/lib/phpqrcode/cache/';

    if (!file_exists($libPath)) {
        throw new RuntimeException('QRコードライブラリが見つかりません');
    }

    require_once $libPath;

    // 一時ファイルにPNG出力
    $tmpFile = tempnam(sys_get_temp_dir(), 'qr_') . '.png';

    QRcode::png($text, $tmpFile, QR_ECLEVEL_M, 6, 2);

    if (!file_exists($tmpFile)) {
        throw new RuntimeException('QRコード生成に失敗しました');
    }

    $imageData = file_get_contents($tmpFile);
    unlink($tmpFile);

    return 'data:image/png;base64,' . base64_encode($imageData);
}

/**
 * QRコード画像をサーバーに保存してURLを返す
 */
function generateQrCodeFile(string $text, string $filename): string
{
    $libPath  = '/home/mogans/www/haptic.irodori.tokyo/lib/phpqrcode/qrlib.php';
    $saveDir  = '/home/mogans/www/haptic.irodori.tokyo/qrcodes/';
    $urlBase  = 'https://haptic.irodori.tokyo/qrcodes/';

    if (!file_exists($libPath)) {
        throw new RuntimeException('QRコードライブラリが見つかりません');
    }

    require_once $libPath;

    if (!is_dir($saveDir)) {
        mkdir($saveDir, 0755, true);
    }

    $savePath = $saveDir . $filename . '.png';
    QRcode::png($text, $savePath, QR_ECLEVEL_M, 6, 2);

    return $urlBase . $filename . '.png';
}
