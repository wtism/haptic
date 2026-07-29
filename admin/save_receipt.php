<?php
/**
 * POST /admin/api/save_receipt.php
 * Body (JSON): { reservation_id, items, discount_amount, coupon_id, payment_method, note, action }
 * action: 'save'(一時保存) | 'pay'(会計確定)
 */
require_once dirname(__DIR__) . '/auth.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { http_response_code(400); echo json_encode(['error' => 'invalid input']); exit; }

$reservationId  = (int)($input['reservation_id'] ?? 0);
$items          = $input['items'] ?? [];
$discountAmount = (int)($input['discount_amount'] ?? 0);
$couponId       = $input['coupon_id'] ? (int)$input['coupon_id'] : null;
$paymentMethod  = $input['payment_method'] ?? 'cash';
$note           = $input['note'] ?? null;
$action         = $input['action'] ?? 'save';  // 'save' or 'pay'

if (!$reservationId || empty($items)) {
    http_response_code(400); echo json_encode(['error' => '予約IDと明細は必須です']); exit;
}

$db = db();

try {
    $db->beginTransaction();

    // 小計・税計算
    $subtotal   = 0;
    $taxAmount  = 0;
    $couponAmt  = 0;

    // クーポン金額取得（%OFFは小計確定後に計算するため後回し）
    $couponAmt  = 0;
    $couponRow  = null;
    if ($couponId) {
        $cpStmt = $db->prepare('SELECT discount, discount_type, discount_rate FROM coupons WHERE id=? AND used_at IS NULL');
        $cpStmt->execute([$couponId]);
        $couponRow = $cpStmt->fetch();
    }

    // 各明細の小計計算
    $calcItems = [];
    foreach ($items as $item) {
        $unitPrice = (int)$item['unit_price'];
        $quantity  = max(1, (int)$item['quantity']);
        $discount  = (int)($item['discount'] ?? 0);
        $taxRate   = (float)($item['tax_rate'] ?? 0.10);
        $itemSubtotal = ($unitPrice * $quantity) - $discount;
        $subtotal    += $itemSubtotal;
        $taxAmount   += (int)round($itemSubtotal * $taxRate / (1 + $taxRate)); // 内税計算

        $calcItems[] = [
            'item_type' => $item['item_type'],
            'item_id'   => (int)$item['item_id'],
            'item_name' => $item['item_name'],
            'unit_price'=> $unitPrice,
            'quantity'  => $quantity,
            'tax_rate'  => $taxRate,
            'discount'  => $discount,
            'subtotal'  => $itemSubtotal,
        ];
    }

    // クーポン金額を確定（%OFFは小計から算出）
    if ($couponRow) {
        if (($couponRow['discount_type'] ?? 'amount') === 'percent') {
            $couponAmt = (int)round($subtotal * (int)$couponRow['discount_rate'] / 100);
        } else {
            $couponAmt = (int)$couponRow['discount'];
        }
    }

    $total = max(0, $subtotal - $discountAmount - $couponAmt);

    // receiptsのUPSERT
    $status = ($action === 'pay') ? 'paid' : 'open';
    $confirmedAt = ($action === 'pay') ? date('Y-m-d H:i:s') : null;
    $confirmedBy = ($action === 'pay') ? currentAdminId() : null;

    $existing = $db->prepare('SELECT id FROM receipts WHERE reservation_id=?');
    $existing->execute([$reservationId]);
    $receiptId = $existing->fetchColumn();

    if ($receiptId) {
        $db->prepare('
            UPDATE receipts SET subtotal=?, tax_amount=?, discount_amount=?, coupon_amount=?,
            coupon_id=?, total=?, payment_method=?, status=?, note=?,
            confirmed_at=?, confirmed_by=?, updated_at=NOW()
            WHERE id=?
        ')->execute([$subtotal, $taxAmount, $discountAmount, $couponAmt,
                     $couponId, $total, $paymentMethod, $status, $note,
                     $confirmedAt, $confirmedBy, $receiptId]);
        // 明細を全削除して再挿入
        $db->prepare('DELETE FROM receipt_items WHERE receipt_id=?')->execute([$receiptId]);
    } else {
        $db->prepare('
            INSERT INTO receipts (reservation_id, subtotal, tax_amount, discount_amount, coupon_amount,
            coupon_id, total, payment_method, status, note, confirmed_at, confirmed_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ')->execute([$reservationId, $subtotal, $taxAmount, $discountAmount, $couponAmt,
                     $couponId, $total, $paymentMethod, $status, $note, $confirmedAt, $confirmedBy]);
        $receiptId = (int)$db->lastInsertId();
    }

    // 明細挿入
    $itemStmt = $db->prepare('
        INSERT INTO receipt_items (receipt_id, item_type, item_id, item_name, unit_price, quantity, tax_rate, discount, subtotal)
        VALUES (?,?,?,?,?,?,?,?,?)
    ');
    foreach ($calcItems as $ci) {
        $itemStmt->execute([$receiptId, $ci['item_type'], $ci['item_id'], $ci['item_name'],
                            $ci['unit_price'], $ci['quantity'], $ci['tax_rate'], $ci['discount'], $ci['subtotal']]);
    }

    // 会計確定時：クーポン使用済みに＆予約ステータスをcompletedに
    if ($action === 'pay') {
        if ($couponId) {
            $db->prepare('UPDATE coupons SET used_at=NOW(), used_reservation_id=? WHERE id=?')
               ->execute([$reservationId, $couponId]);
        }
        $db->prepare('UPDATE reservations SET status="completed", updated_at=NOW() WHERE id=?')
           ->execute([$reservationId]);
    }

    $db->commit();
    echo json_encode(['success' => true, 'receipt_id' => $receiptId, 'total' => $total]);

} catch (Throwable $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
