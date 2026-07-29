<?php
/**
 * admin/register.php — レジ画面
 * GET ?reservation_id=XX
 */
require_once __DIR__ . '/auth.php';
requireLogin();

$db            = db();
$reservationId = (int)($_GET['reservation_id'] ?? 0);
if (!$reservationId) { header('Location: ' . adminUrl('reservations.php')); exit; }

// 予約取得
$stmt = $db->prepare('
    SELECT r.*, c.name AS customer_name, c.id AS customer_id,
           s.name AS staff_name, s.id AS staff_id_val,
           m.name AS menu_name, m.price AS menu_price, 0.10 AS menu_tax_rate
    FROM reservations r
    LEFT JOIN customers c ON r.customer_id = c.id
    LEFT JOIN staff    s ON r.staff_id = s.id
    LEFT JOIN menus    m ON r.menu_id  = m.id
    WHERE r.id = ?
');
$stmt->execute([$reservationId]);
$res = $stmt->fetch();
if (!$res) { header('Location: ' . adminUrl('reservations.php')); exit; }

// 既存会計
$receipt = $db->prepare('SELECT * FROM receipts WHERE reservation_id=?');
$receipt->execute([$reservationId]);
$receipt = $receipt->fetch();

$receiptItems = [];
if ($receipt) {
    $riStmt = $db->prepare('SELECT * FROM receipt_items WHERE receipt_id=? ORDER BY id');
    $riStmt->execute([$receipt['id']]);
    $receiptItems = $riStmt->fetchAll();
}

// 利用可能クーポン
$couponStmt = $db->prepare('
    SELECT * FROM coupons
    WHERE customer_id=? AND used_at IS NULL AND (expired_at IS NULL OR expired_at > NOW())
    ORDER BY issued_at DESC
');
$couponStmt->execute([$res['customer_id']]);
$availCoupons = $couponStmt->fetchAll();

$pageTitle = 'レジ #' . $reservationId;
include __DIR__ . '/_header.php';
?>

<style>
.register-wrap { display:grid; grid-template-columns:1fr 360px; gap:20px; }
.items-table { width:100%; border-collapse:collapse; font-size:0.9em; }
.items-table th { background:#f1f5f0; padding:8px 10px; text-align:left; font-weight:600; }
.items-table td { padding:8px 10px; border-bottom:1px solid #eee; vertical-align:middle; }
.items-table input[type=number] { width:70px; padding:4px 6px; border:1px solid #ddd; border-radius:4px; }
.summary-box { background:#fff; border-radius:10px; border:1px solid #e0e0e0; overflow:hidden; position:sticky; top:20px; }
.summary-row { display:flex; justify-content:space-between; padding:10px 16px; border-bottom:1px solid #f0f0f0; font-size:0.92em; }
.summary-row.total { font-size:1.2em; font-weight:bold; background:#f8fdf8; padding:14px 16px; }
.add-item-row { display:flex; gap:8px; padding:12px 0; align-items:flex-end; flex-wrap:wrap; }
.add-item-row select, .add-item-row input { padding:6px 8px; border:1px solid #ddd; border-radius:6px; }
.badge-paid { background:#28a745; color:#fff; padding:3px 10px; border-radius:12px; font-size:0.75em; }
.badge-open { background:#ffc107; color:#333; padding:3px 10px; border-radius:12px; font-size:0.75em; }
</style>

<div class="page-title">
    <a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $reservationId ?>" style="font-size:0.7em;font-weight:normal;">← 予約詳細</a><br>
    レジ — <?= h($res['customer_name']) ?>様
    <?php if ($receipt): ?>
    <span class="badge-<?= $receipt['status'] === 'paid' ? 'paid' : 'open' ?>">
        <?= $receipt['status'] === 'paid' ? '✅ 会計済み' : '編集中' ?>
    </span>
    <?php endif; ?>
</div>

<div class="register-wrap">

<!-- 左：明細 -->
<div>
<div class="card">
    <div class="card-header">会計明細</div>
    <div class="card-body" style="padding:0;">
        <table class="items-table" id="itemsTable">
            <thead>
                <tr>
                    <th style="width:40%;">項目</th>
                    <th style="width:12%;">種別</th>
                    <th style="width:12%;">単価</th>
                    <th style="width:8%;">数量</th>
                    <th style="width:10%;">値引</th>
                    <th style="width:10%;">小計</th>
                    <th style="width:8%;"></th>
                </tr>
            </thead>
            <tbody id="itemsBody">
                <!-- JSで描画 -->
            </tbody>
        </table>

        <!-- 明細追加 -->
        <?php if (!$receipt || $receipt['status'] !== 'paid'): ?>
        <div style="padding:12px 16px;background:#f8f9fa;border-top:1px solid #eee;">
            <div style="font-size:0.85em;font-weight:600;margin-bottom:8px;color:#555;">＋ 項目を追加</div>
            <div class="add-item-row">
                <div>
                    <select id="addItemType" onchange="filterAddItems()" style="width:100px;">
                        <option value="menu">施術</option>
                        <option value="product">物販</option>
                    </select>
                </div>
                <div style="flex:1;">
                    <select id="addItemSelect" style="width:100%;min-width:200px;">
                        <option value="">読み込み中...</option>
                    </select>
                </div>
                <div><input type="number" id="addQty" value="1" min="1" style="width:60px;" placeholder="数量"></div>
                <button class="btn btn-primary btn-sm" onclick="addItem()">追加</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- 右：集計・支払い -->
<div>
<div class="summary-box">
    <div style="padding:14px 16px;background:#6B9E8A;color:#fff;font-weight:bold;">
        💰 会計サマリー
    </div>

    <!-- クーポン -->
    <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;">
        <label style="font-size:0.85em;font-weight:600;display:block;margin-bottom:6px;">クーポン</label>
        <select id="couponSelect" onchange="calcTotal()" style="width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;">
            <option value="">使用しない</option>
            <?php foreach ($availCoupons as $cp):
                $isPercent = ($cp['discount_type'] ?? 'amount') === 'percent';
                $dLabel = $isPercent ? ($cp['discount_rate'].'% OFF') : ('-¥'.number_format($cp['discount']));
            ?>
            <option value="<?= $cp['id'] ?>"
                data-amount="<?= $cp['discount'] ?>"
                data-type="<?= h($cp['discount_type'] ?? 'amount') ?>"
                data-rate="<?= (int)($cp['discount_rate'] ?? 0) ?>"
                <?= ($receipt && $receipt['coupon_id']==$cp['id']) ? 'selected' : '' ?>>
                <?= h($cp['description']) ?> ▶ <?= $dLabel ?>（<?= h($cp['code']) ?>）
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- 値引き -->
    <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;">
        <label style="font-size:0.85em;font-weight:600;display:block;margin-bottom:6px;">値引き（円）</label>
        <input type="number" id="discountInput" value="<?= (int)($receipt['discount_amount'] ?? 0) ?>"
               min="0" oninput="calcTotal()"
               style="width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;">
    </div>

    <!-- 集計 -->
    <div class="summary-row"><span>小計</span><span id="sumSubtotal">¥0</span></div>
    <div class="summary-row"><span>消費税（内税）</span><span id="sumTax">¥0</span></div>
    <div class="summary-row"><span>クーポン割引</span><span id="sumCoupon" style="color:#e74c3c;">-¥0</span></div>
    <div class="summary-row"><span>値引き</span><span id="sumDiscount" style="color:#e74c3c;">-¥0</span></div>
    <div class="summary-row total"><span>合計</span><span id="sumTotal">¥0</span></div>

    <!-- 支払い方法 -->
    <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;">
        <label style="font-size:0.85em;font-weight:600;display:block;margin-bottom:6px;">支払い方法</label>
        <select id="paymentMethod" style="width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;">
            <option value="cash" <?= ($receipt['payment_method']??'cash')==='cash'?'selected':'' ?>>💴 現金</option>
            <option value="card" <?= ($receipt['payment_method']??'')==='card'?'selected':'' ?>>💳 カード</option>
            <option value="paypay" <?= ($receipt['payment_method']??'')==='paypay'?'selected':'' ?>>📱 PayPay</option>
            <option value="line_pay" <?= ($receipt['payment_method']??'')==='line_pay'?'selected':'' ?>>💚 LINE Pay</option>
            <option value="other" <?= ($receipt['payment_method']??'')==='other'?'selected':'' ?>>その他</option>
        </select>
    </div>

    <!-- 備考 -->
    <div style="padding:12px 16px;border-bottom:1px solid #f0f0f0;">
        <label style="font-size:0.85em;font-weight:600;display:block;margin-bottom:6px;">備考</label>
        <textarea id="receiptNote" rows="2" style="width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;font-size:0.9em;"><?= h($receipt['note'] ?? '') ?></textarea>
    </div>

    <?php if (!$receipt || $receipt['status'] !== 'paid'): ?>
    <!-- ボタン -->
    <div style="padding:14px 16px;display:flex;flex-direction:column;gap:8px;">
        <button class="btn" style="background:#6B9E8A;color:#fff;width:100%;padding:12px;font-size:1em;font-weight:bold;" onclick="saveReceipt('pay')">
            ✅ 会計を確定する
        </button>
        <button class="btn btn-secondary" style="width:100%;" onclick="saveReceipt('save')">
            💾 一時保存
        </button>
    </div>
    <?php else: ?>
    <div style="padding:14px 16px;text-align:center;color:#28a745;font-weight:bold;">
        ✅ 会計確定済み（<?= date('Y/m/d H:i', strtotime($receipt['confirmed_at'])) ?>）
    </div>
    <?php endif; ?>
</div>
</div>
</div><!-- /register-wrap -->

<script>
const RESERVATION_ID = <?= $reservationId ?>;
const CSRF           = '<?= csrf() ?>';
let allMenus    = [];
let allProducts = [];
let items       = []; // 現在の明細

// 既存明細の初期値
const existingItems = <?= json_encode(array_map(fn($i) => [
    'item_type'  => $i['item_type'],
    'item_id'    => (int)$i['item_id'],
    'item_name'  => $i['item_name'],
    'unit_price' => (int)$i['unit_price'],
    'quantity'   => (int)$i['quantity'],
    'tax_rate'   => (float)$i['tax_rate'],
    'discount'   => (int)$i['discount'],
], $receiptItems), JSON_UNESCAPED_UNICODE) ?>;

const isPaid = <?= ($receipt && $receipt['status'] === 'paid') ? 'true' : 'false' ?>;

// アイテム＆商品マスタ取得
fetch('<?= adminUrl('api/get_items.php') ?>')
    .then(r => r.json())
    .then(data => {
        allMenus    = data.menus;
        allProducts = data.products;
        filterAddItems();

        // 既存明細があればセット、なければデフォルトでメニューを追加
        if (existingItems.length > 0) {
            items = existingItems;
        } else {
            <?php if ($res['menu_id']): ?>
            const defaultMenu = allMenus.find(m => m.id == <?= (int)$res['menu_id'] ?>);
            if (defaultMenu) {
                items.push({
                    item_type: 'menu', item_id: defaultMenu.id,
                    item_name: defaultMenu.name, unit_price: defaultMenu.price,
                    quantity: 1, tax_rate: 0.10, discount: 0
                });
            }
            <?php endif; ?>
        }
        renderItems();
        calcTotal();
    });

function filterAddItems() {
    const type = document.getElementById('addItemType').value;
    const sel  = document.getElementById('addItemSelect');
    const list = type === 'menu' ? allMenus : allProducts;
    sel.innerHTML = list.map(i =>
        `<option value="${i.id}" data-price="${i.price}" data-tax="${i.tax_rate}" data-name="${escAttr(i.name)}">
            ${escHtml(i.name)}（¥${i.price.toLocaleString()}）
        </option>`
    ).join('');
}

function addItem() {
    const type = document.getElementById('addItemType').value;
    const sel  = document.getElementById('addItemSelect');
    const opt  = sel.options[sel.selectedIndex];
    const qty  = parseInt(document.getElementById('addQty').value) || 1;
    if (!opt || !opt.value) return;
    items.push({
        item_type : type,
        item_id   : parseInt(opt.value),
        item_name : opt.dataset.name,
        unit_price: parseInt(opt.dataset.price),
        quantity  : qty,
        tax_rate  : parseFloat(opt.dataset.tax),
        discount  : 0,
    });
    renderItems();
    calcTotal();
}

function removeItem(idx) {
    items.splice(idx, 1);
    renderItems();
    calcTotal();
}

function renderItems() {
    const body = document.getElementById('itemsBody');
    if (items.length === 0) {
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:#aaa;">明細がありません</td></tr>';
        return;
    }
    body.innerHTML = items.map((item, idx) => {
        const subtotal = (item.unit_price * item.quantity) - item.discount;
        const typeLabel = item.item_type === 'menu' ? '<span style="background:#d4edda;color:#155724;padding:2px 6px;border-radius:4px;font-size:0.8em;">施術</span>'
                                                    : '<span style="background:#cce5ff;color:#004085;padding:2px 6px;border-radius:4px;font-size:0.8em;">物販</span>';
        const editDisabled = isPaid ? 'disabled' : '';
        return `<tr>
            <td>${escHtml(item.item_name)}</td>
            <td>${typeLabel}</td>
            <td>¥${item.unit_price.toLocaleString()}</td>
            <td><input type="number" value="${item.quantity}" min="1" ${editDisabled}
                       onchange="updateItem(${idx},'quantity',this.value)"></td>
            <td><input type="number" value="${item.discount}" min="0" ${editDisabled}
                       onchange="updateItem(${idx},'discount',this.value)" style="width:75px;"></td>
            <td style="font-weight:bold;">¥${subtotal.toLocaleString()}</td>
            <td>${!isPaid ? `<button onclick="removeItem(${idx})" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:1.1em;">✕</button>` : ''}</td>
        </tr>`;
    }).join('');
}

function updateItem(idx, field, val) {
    items[idx][field] = parseInt(val) || 0;
    renderItems();
    calcTotal();
}

function calcTotal() {
    let subtotal = 0, tax = 0;
    items.forEach(item => {
        const itemSub = (item.unit_price * item.quantity) - item.discount;
        subtotal += itemSub;
        tax      += Math.round(itemSub * item.tax_rate / (1 + item.tax_rate));
    });
    const couponSel = document.getElementById('couponSelect');
    let couponAmt = 0;
    if (couponSel.selectedIndex > 0) {
        const opt = couponSel.options[couponSel.selectedIndex];
        if (opt.dataset.type === 'percent') {
            couponAmt = Math.round(subtotal * parseInt(opt.dataset.rate) / 100);
        } else {
            couponAmt = parseInt(opt.dataset.amount) || 0;
        }
    }
    const discount  = parseInt(document.getElementById('discountInput').value) || 0;
    const total     = Math.max(0, subtotal - couponAmt - discount);

    document.getElementById('sumSubtotal').textContent = '¥' + subtotal.toLocaleString();
    document.getElementById('sumTax').textContent      = '¥' + tax.toLocaleString();
    document.getElementById('sumCoupon').textContent   = '-¥' + couponAmt.toLocaleString();
    document.getElementById('sumDiscount').textContent = '-¥' + discount.toLocaleString();
    document.getElementById('sumTotal').textContent    = '¥' + total.toLocaleString();
}

function saveReceipt(action) {
    if (action === 'pay' && !confirm('会計を確定します。よろしいですか？')) return;
    const couponSel = document.getElementById('couponSelect');
    const couponId  = couponSel.value ? parseInt(couponSel.value) : null;
    const payload = {
        reservation_id  : RESERVATION_ID,
        items           : items,
        discount_amount : parseInt(document.getElementById('discountInput').value) || 0,
        coupon_id       : couponId,
        payment_method  : document.getElementById('paymentMethod').value,
        note            : document.getElementById('receiptNote').value,
        action          : action,
        csrf_token      : CSRF,
    };
    fetch('<?= adminUrl('api/save_receipt.php') ?>', {
        method : 'POST',
        headers: {'Content-Type': 'application/json'},
        body   : JSON.stringify(payload),
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert(action === 'pay' ? '✅ 会計を確定しました！' : '💾 一時保存しました');
            location.reload();
        } else {
            alert('❌ エラー: ' + (data.error || '保存に失敗しました'));
        }
    });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(s) {
    return String(s).replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
