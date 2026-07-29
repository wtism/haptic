<?php
/**
 * GET /admin/api/get_items.php?staff_id=1
 * Response: { menus, products, menu_components, staff_prices, nomination_fee }
 */
require_once dirname(__DIR__) . '/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$db       = db();
$staffId  = (int)($_GET['staff_id'] ?? 0);

// メニュー一覧
$menus = $db->query("
    SELECT id, category, name, price, duration_min, 0.10 AS tax_rate
    FROM menus WHERE is_active=1 ORDER BY display_order
")->fetchAll(PDO::FETCH_ASSOC);

// 商品一覧
$products = $db->query("
    SELECT id, category, name, price, tax_rate, unit, maker
    FROM products WHERE is_active=1 AND status='active' ORDER BY category, display_order
")->fetchAll(PDO::FETCH_ASSOC);

// メニューセット構成
$components = $db->query("
    SELECT parent_menu_id, child_menu_id, display_order
    FROM menu_components ORDER BY parent_menu_id, display_order
")->fetchAll(PDO::FETCH_ASSOC);

// セット構成をparent_menu_idでグループ化
$menuComponents = [];
foreach ($components as $c) {
    $menuComponents[(int)$c['parent_menu_id']][] = (int)$c['child_menu_id'];
}

// スタッフ個別価格 & 指名料
$staffPrices   = [];
$nominationFee = 0;
if ($staffId) {
    $priceStmt = $db->prepare("
        SELECT menu_id, price FROM staff_menu_prices WHERE staff_id=?
    ");
    $priceStmt->execute([$staffId]);
    foreach ($priceStmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $staffPrices[(int)$p['menu_id']] = (int)$p['price'];
    }

    $nomStmt = $db->prepare("SELECT nomination_fee FROM staff WHERE id=?");
    $nomStmt->execute([$staffId]);
    $nominationFee = (int)($nomStmt->fetchColumn() ?? 0);
}

// 数値型変換
foreach ($menus as &$m) {
    $m['id']       = (int)$m['id'];
    $m['price']    = (int)$m['price'];
    $m['tax_rate'] = (float)$m['tax_rate'];
}
foreach ($products as &$p) {
    $p['id']       = (int)$p['id'];
    $p['price']    = (int)$p['price'];
    $p['tax_rate'] = (float)$p['tax_rate'];
}

echo json_encode([
    'menus'           => $menus,
    'products'        => $products,
    'menu_components' => $menuComponents,
    'staff_prices'    => $staffPrices,
    'nomination_fee'  => $nominationFee,
], JSON_UNESCAPED_UNICODE);
