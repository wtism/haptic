<?php
// 在庫アラートを店舗設定で一括ON/OFFできるようにする（2026-08-03）
// - shop_settings.stock_alert_enabled を追加（既定=ON）
// - 商品ごとのフラグ products.alert_enabled は不要になったため削除
require_once '/home/mogans/www/haptic.irodori.tokyo/config/db.php';

$d = db();

$hasSetting = false;
foreach ($d->query('SHOW COLUMNS FROM shop_settings') as $c) {
    if ($c['Field'] === 'stock_alert_enabled') $hasSetting = true;
}
if ($hasSetting) {
    echo 'SKIP: shop_settings.stock_alert_enabled は既に存在します' . PHP_EOL;
} else {
    $d->exec("ALTER TABLE shop_settings
              ADD COLUMN stock_alert_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=在庫アラートを表示しない'
              AFTER fortune_enabled");
    echo 'OK: shop_settings.stock_alert_enabled を追加しました' . PHP_EOL;
}

$hasProductCol = false;
foreach ($d->query('SHOW COLUMNS FROM products') as $c) {
    if ($c['Field'] === 'alert_enabled') $hasProductCol = true;
}
if ($hasProductCol) {
    $d->exec('ALTER TABLE products DROP COLUMN alert_enabled');
    echo 'OK: products.alert_enabled を削除しました' . PHP_EOL;
} else {
    echo 'SKIP: products.alert_enabled は存在しません' . PHP_EOL;
}

$row = $d->query('SELECT id, shop_name, fortune_enabled, stock_alert_enabled FROM shop_settings WHERE id=1')->fetch();
echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
