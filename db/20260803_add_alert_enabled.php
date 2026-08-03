<?php
// products に「在庫アラートを出す／出さない」フラグを追加（2026-08-03）
require_once '/home/mogans/www/haptic.irodori.tokyo/config/db.php';

$d = db();

$exists = false;
foreach ($d->query('SHOW COLUMNS FROM products') as $c) {
    if ($c['Field'] === 'alert_enabled') $exists = true;
}
if ($exists) {
    echo 'SKIP: products.alert_enabled は既に存在します' . PHP_EOL;
    exit(0);
}

$d->exec("ALTER TABLE products
          ADD COLUMN alert_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=在庫アラートを出さない'
          AFTER stock_alert");

echo 'OK: products.alert_enabled を追加しました' . PHP_EOL;
foreach ($d->query('SELECT id, name, item_type, stock, stock_alert, alert_enabled FROM products ORDER BY id') as $r) {
    echo '  ' . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
