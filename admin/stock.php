<?php
// admin/stock.php  - 在庫・入荷管理（LOT管理）
require_once __DIR__ . '/auth.php';
requireLogin();

$db  = db();
$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // 入荷登録
    if ($action === 'add_purchase') {
        $productId  = (int)$_POST['product_id'];
        $quantity   = max(1, (int)$_POST['quantity']);
        $costPrice  = (int)$_POST['cost_price'];
        $totalCost  = $quantity * $costPrice;
        $purchasedAt = $_POST['purchased_at'] ?: date('Y-m-d');

        $db->prepare('
            INSERT INTO stock_purchases (product_id, lot_number, quantity, cost_price, total_cost, purchased_at, expiry_date, supplier, note, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ')->execute([
            $productId,
            $_POST['lot_number'] ?: null,
            $quantity, $costPrice, $totalCost,
            $purchasedAt,
            $_POST['expiry_date'] ?: null,
            $_POST['supplier'] ?: null,
            $_POST['note'] ?: null,
            currentAdminId(),
        ]);

        // 在庫数を増加
        $db->prepare('UPDATE products SET stock = stock + ? WHERE id=?')->execute([$quantity, $productId]);

        auditLog('create', 'stock_purchase', (int)$db->lastInsertId(), "入荷：商品ID{$productId} {$quantity}個");
        header('Location: ' . adminUrl('stock.php') . '?msg=added&product_id=' . $productId); exit;
    }

    // 在庫数直接調整
    if ($action === 'stock_adjust') {
        $productId = (int)$_POST['product_id'];
        $newStock  = max(0, (int)$_POST['new_stock']);
        $db->prepare('UPDATE products SET stock=? WHERE id=?')->execute([$newStock, $productId]);
        auditLog('update', 'product', $productId, "在庫数調整：{$newStock}");
        header('Location: ' . adminUrl('stock.php') . '?msg=stock_adjusted&product_id=' . $productId); exit;
    }

    // 入荷削除（在庫も戻す）
    if ($action === 'delete_purchase') {
        $purchaseId = (int)$_POST['id'];
        $stmt = $db->prepare('SELECT * FROM stock_purchases WHERE id=?');
        $stmt->execute([$purchaseId]);
        $purchase = $stmt->fetch();
        if ($purchase) {
            $db->prepare('UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?')
               ->execute([$purchase['quantity'], $purchase['product_id']]);
            $db->prepare('DELETE FROM stock_purchases WHERE id=?')->execute([$purchaseId]);
        }
        header('Location: ' . adminUrl('stock.php') . '?msg=deleted'); exit;
    }
}

$msgMap = ['added'=>'入荷を登録しました','deleted'=>'入荷記録を削除しました','stock_adjusted'=>'在庫数を更新しました'];
if (isset($msgMap[$_GET['msg'] ?? ''])) { $msg = $msgMap[$_GET['msg']]; if ($_GET['msg']==='deleted') $msgType='danger'; }

// 絞り込み
$filterProductId = (int)($_GET['product_id'] ?? 0);
$filterType      = $_GET['type'] ?? '';

// 商品一覧（プルダウン用）
$allProducts = $db->query("SELECT * FROM products WHERE is_active=1 ORDER BY item_type, category, display_order")->fetchAll();

// 入荷履歴
$where  = ['1=1']; $params = [];
if ($filterProductId) { $where[] = 'sp.product_id=?'; $params[] = $filterProductId; }
if ($filterType)      { $where[] = 'p.item_type=?';   $params[] = $filterType; }

$stmt = $db->prepare("
    SELECT sp.*, p.name AS product_name, p.maker, p.item_type, p.unit, p.category
    FROM stock_purchases sp
    JOIN products p ON sp.product_id = p.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY sp.purchased_at DESC, sp.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$purchases = $stmt->fetchAll();

// 在庫アラート
$stockAlerts = $db->query("
    SELECT * FROM products
    WHERE is_active=1 AND status='active' AND alert_enabled=1 AND stock <= stock_alert
    ORDER BY item_type, stock ASC
")->fetchAll();

// 在庫サマリー（LOT別）
$stockSummary = $db->query("
    SELECT p.*, 
           COUNT(sp.id) AS lot_count,
           SUM(sp.total_cost) AS total_invested,
           MIN(sp.expiry_date) AS nearest_expiry
    FROM products p
    LEFT JOIN stock_purchases sp ON p.id = sp.product_id
    WHERE p.is_active=1 AND p.status='active'
    GROUP BY p.id
    ORDER BY p.item_type, p.category, p.display_order
")->fetchAll();

$categoryLabels = ['shampoo'=>'シャンプー','treatment'=>'トリートメント','outbath'=>'アウトバス','styling'=>'スタイリング','color'=>'カラー剤','perm'=>'パーマ剤','sanitary'=>'衛生用品','equipment'=>'器具・備品','other'=>'その他'];

$pageTitle = '在庫・入荷管理';
include __DIR__ . '/_header.php';
?>

<div class="page-title">在庫・入荷管理（LOT管理）</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<?php if (!empty($stockAlerts)): ?>
<div class="alert alert-danger" style="display:flex;align-items:flex-start;gap:12px;">
    <div style="font-size:1.3em;">⚠️</div>
    <div>
        <strong style="display:block;margin-bottom:6px;">在庫アラート</strong>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach ($stockAlerts as $a): ?>
            <a href="?product_id=<?= $a['id'] ?>" style="text-decoration:none;">
                <span style="background:#fff;border:1px solid #f5c6cb;padding:3px 12px;border-radius:12px;font-size:0.88em;color:#333;">
                    <?= $a['item_type']==='material'?'🔧 ':'' ?><?= h($a['name']) ?>
                    <span style="color:<?= $a['stock']<=0?'#dc3545':'#856404' ?>;font-weight:bold;"> 残<?= $a['stock'] ?><?= h($a['unit']) ?></span>
                    <span style="color:#888;font-size:0.85em;">/ アラート<?= $a['stock_alert'] ?>以下</span>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

<!-- 入荷登録 -->
<div>
<div class="card">
    <div class="card-header">📦 入荷登録</div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add_purchase">
            <div class="form-group">
                <label>商品 *</label>
                <select name="product_id" required onchange="setDefaultCost(this)">
                    <option value="">-- 選択 --</option>
                    <?php
                    $curType = '';
                    foreach ($allProducts as $p):
                        if ($p['item_type'] !== $curType) {
                            if ($curType) echo '</optgroup>';
                            $curType = $p['item_type'];
                            echo '<optgroup label="' . ($curType==='product'?'🛍 販売商品':'🔧 資材') . '">';
                        }
                    ?>
                    <option value="<?= $p['id'] ?>" data-cost="<?= h($p['cost_price'] ?? 0) ?>" <?= $filterProductId==$p['id']?'selected':'' ?>>
                        <?= h($p['name']) ?><?= $p['maker']?' /'.h($p['maker']):'' ?>（在庫<?= $p['stock'] ?><?= h($p['unit']) ?>）
                    </option>
                    <?php endforeach; if ($curType) echo '</optgroup>'; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group"><label>入荷数量</label><input type="number" name="quantity" value="1" min="1" required></div>
                <div class="form-group"><label>仕入単価（円）</label><input type="number" name="cost_price" id="costPriceInput" value="0" min="0" required></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group"><label>入荷日</label><input type="date" name="purchased_at" value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label>有効期限</label><input type="date" name="expiry_date"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group"><label>LOT番号</label><input type="text" name="lot_number" placeholder="例：2026001"></div>
                <div class="form-group"><label>仕入先</label><input type="text" name="supplier" placeholder="例：〇〇商事"></div>
            </div>
            <div class="form-group"><label>備考</label><input type="text" name="note" placeholder="任意"></div>
            <button class="btn btn-primary" type="submit">入荷登録</button>
        </form>
    </div>
</div>
</div>

<!-- 在庫サマリー -->
<div>
<div class="card">
    <div class="card-header">📊 在庫サマリー</div>
    <div style="padding:0;max-height:480px;overflow-y:auto;">
        <table>
            <tr><th>商品名</th><th>在庫</th><th>在庫調整</th><th>投資額</th><th>期限</th></tr>
            <?php
            $curCat = '';
            foreach ($stockSummary as $p):
                $catKey = $p['category'];
                if ($catKey !== $curCat):
                    $curCat = $catKey;
            ?>
            <tr><td colspan="5" style="background:#f8f9fa;font-size:0.82em;color:#6B9E8A;font-weight:bold;padding:6px 12px;"><?= h($categoryLabels[$curCat] ?? $curCat) ?></td></tr>
            <?php endif; ?>
            <tr>
                <td>
                    <a href="?product_id=<?= $p['id'] ?>" style="font-size:0.9em;"><?= h($p['name']) ?></a>
                    <?php if ($p['maker']): ?><span style="color:#888;font-size:0.78em;"> /<?= h($p['maker']) ?></span><?php endif; ?>
                </td>
                <td>
                    <?php $sc = empty($p['alert_enabled'])
                        ? '#e9ecef;color:#6c757d'
                        : ($p['stock'] <= 0 ? '#f8d7da;color:#721c24' : ($p['stock'] <= $p['stock_alert'] ? '#fff3cd;color:#856404' : '#d4edda;color:#155724')); ?>
                    <span style="background:<?= $sc ?>;padding:2px 8px;border-radius:10px;font-size:0.85em;font-weight:bold;">
                        <?= $p['stock'] ?><?= h($p['unit']) ?>
                    </span>
                    <?php if (empty($p['alert_enabled'])): ?><span title="在庫アラートOFF" style="font-size:0.85em;">🔕</span><?php endif; ?>
                </td>
                <td>
                    <form method="post" style="display:inline-flex;gap:4px;align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="stock_adjust">
                        <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                        <input type="number" name="new_stock" value="<?= $p['stock'] ?>" min="0" style="width:55px;padding:3px 6px;font-size:0.85em;border:1px solid #ddd;border-radius:4px;">
                        <button type="submit" class="btn btn-sm btn-primary" style="padding:3px 8px;white-space:nowrap;">更新</button>
                    </form>
                </td>
                <td style="font-size:0.88em;">
                    <?= $p['total_invested'] ? '¥'.number_format($p['total_invested']) : '-' ?>
                </td>
                <td style="font-size:0.82em;<?= ($p['nearest_expiry'] && strtotime($p['nearest_expiry']) < strtotime('+30 days')) ? 'color:#e74c3c;font-weight:bold;' : 'color:#888;' ?>">
                    <?= $p['nearest_expiry'] ? h(date('Y/m/d', strtotime($p['nearest_expiry']))) : '-' ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
</div>
</div>

<!-- 入荷履歴 -->
<div class="card">
    <div class="card-header">
        入荷履歴
        <div style="display:flex;gap:8px;">
            <a href="<?= adminUrl('stock.php') ?>" class="btn btn-sm <?= !$filterType&&!$filterProductId?'btn-primary':'btn-secondary' ?>">全て</a>
            <a href="<?= adminUrl('stock.php') ?>?type=product"  class="btn btn-sm <?= $filterType==='product' ?'btn-primary':'btn-secondary' ?>">販売商品</a>
            <a href="<?= adminUrl('stock.php') ?>?type=material" class="btn btn-sm <?= $filterType==='material'?'btn-primary':'btn-secondary' ?>">資材</a>
        </div>
    </div>
    <div style="padding:0;">
        <table>
            <tr><th>入荷日</th><th>商品</th><th>LOT番号</th><th>数量</th><th>仕入単価</th><th>合計</th><th>有効期限</th><th>仕入先</th><th>操作</th></tr>
            <?php foreach ($purchases as $p): ?>
            <tr>
                <td style="white-space:nowrap;"><?= h(date('Y/m/d', strtotime($p['purchased_at']))) ?></td>
                <td>
                    <?= $p['item_type']==='material'?'🔧 ':'' ?>
                    <?= h($p['product_name']) ?>
                    <?php if ($p['maker']): ?><span style="color:#888;font-size:0.82em;"> /<?= h($p['maker']) ?></span><?php endif; ?>
                </td>
                <td><code style="background:#f0f0f0;padding:1px 6px;border-radius:3px;font-size:0.88em;"><?= h($p['lot_number'] ?? '-') ?></code></td>
                <td><?= h($p['quantity']) ?><?= h($p['unit']) ?></td>
                <td>¥<?= number_format($p['cost_price']) ?></td>
                <td style="font-weight:bold;">¥<?= number_format($p['total_cost']) ?></td>
                <td style="font-size:0.85em;<?= ($p['expiry_date'] && strtotime($p['expiry_date']) < strtotime('+30 days')) ? 'color:#e74c3c;font-weight:bold;' : '' ?>">
                    <?= $p['expiry_date'] ? h(date('Y/m/d', strtotime($p['expiry_date']))) : '-' ?>
                </td>
                <td style="font-size:0.85em;color:#888;"><?= h($p['supplier'] ?? '-') ?></td>
                <td>
                    <form method="post" style="display:inline;" onsubmit="return confirm('削除すると在庫数も戻ります。よろしいですか？')">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="delete_purchase">
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <button class="btn btn-danger btn-sm">削除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($purchases)): ?><tr><td colspan="9" style="text-align:center;padding:20px;color:#888;">入荷履歴がありません</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<script>
function setDefaultCost(sel) {
    const opt = sel.options[sel.selectedIndex];
    const el  = document.getElementById('costPriceInput');
    if (el) el.value = opt.getAttribute('data-cost') || 0;
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
