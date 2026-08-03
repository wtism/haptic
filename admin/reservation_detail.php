<?php


// admin/reservation_detail.php  - 予約詳細・編集
require_once __DIR__ . '/auth.php';
requireLogin();

$db  = db();
$id  = (int)($_GET['id'] ?? 0);
$msg = '';

if (!$id) { header('Location: ' . adminUrl('reservations.php')); exit; }

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $startAt = $_POST['date'] . ' ' . $_POST['time'] . ':00';
        $menuId  = (int)$_POST['menu_id'];
        $staffId = $_POST['staff_id'] ? (int)$_POST['staff_id'] : null;

        // メニューから終了時間を計算
        $menu = $db->prepare('SELECT duration_min FROM menus WHERE id = ?');
        $menu->execute([$menuId]);
        $menu = $menu->fetch();
        $endAt = date('Y-m-d H:i:s', strtotime($startAt) + ($menu['duration_min'] ?? 60) * 60);

        $db->prepare('
            UPDATE reservations SET start_at=?, end_at=?, menu_id=?, staff_id=?, note=?, updated_by=?, updated_at=NOW()
            WHERE id=?
        ')->execute([$startAt, $endAt, $menuId, $staffId, $_POST['note'], currentAdminId(), $id]);
        auditLog('update', 'reservation', $id, '予約内容更新');
        $msg = '予約内容を更新しました';
    }

    if ($action === 'status') {
        $status = $_POST['status'];
        $db->prepare('UPDATE reservations SET status=?, updated_at=NOW() WHERE id=?')->execute([$status, $id]);

        // LINE通知
        $stmt = $db->prepare('
            SELECT r.start_at, c.line_user_id, c.name AS cname, m.name AS mname, s.name AS sname
            FROM reservations r
            LEFT JOIN customers c ON r.customer_id = c.id
            LEFT JOIN menus m ON r.menu_id = m.id
            LEFT JOIN staff s ON r.staff_id = s.id
            WHERE r.id = ?
        ');
        $stmt->execute([$id]);
        $res = $stmt->fetch();
        if ($res && $res['line_user_id']) {
            require_once dirname(__DIR__) . '/lib/line.php';
            $dow = ['日','月','火','水','木','金','土'][date('w', strtotime($res['start_at']))];
            $dt  = date('m月d日（'.$dow.'） H:i', strtotime($res['start_at']));
            $text = match($status) {
                'confirmed' => "✅ ご予約が確定しました！\n\n📅 {$dt}〜\n✂️ {$res['mname']}\n👤 担当：{$res['sname']}\n\nご来店をお待ちしております😊",
                'cancelled' => "ご予約のキャンセルについてご連絡いたします🙏\n\nご希望に添えず大変申し訳ございません。\n改めてご予約はトークよりお知らせください。",
                'completed' => "本日はご来店ありがとうございました✨\nまたのご来店をお待ちしております😊",
                default     => null,
            };
            if ($text) linePush($res['line_user_id'], [textMessage($text)]);
        }
        $msg = 'ステータスを更新しLINEで通知しました';
    }

    // クーポン照合・使用済みに
    // 物販追加
    if ($action === 'add_sale') {
        $productId = (int)$_POST['product_id'];
        $quantity  = max(1, (int)$_POST['quantity']);
        $salePrice = (int)$_POST['sale_price'];
        $soldAt    = $_POST['sold_at'] ?: date('Y-m-d');
        $remindM   = $_POST['remind_months'] ? (int)$_POST['remind_months'] : null;
        $remind    = isset($_POST['remind_enabled']) ? 1 : 0;

        // 予約のcustomer_idを取得
        $custStmt = $db->prepare('SELECT customer_id FROM reservations WHERE id=?');
        $custStmt->execute([$id]);
        $customerId = $custStmt->fetchColumn();

        $db->prepare('
            INSERT INTO product_sales (customer_id, product_id, reservation_id, quantity, price, sold_at, remind_months, remind_enabled, note, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ')->execute([$customerId, $productId, $id, $quantity, $salePrice, $soldAt, $remindM, $remind, $_POST['sale_note'] ?: null, currentAdminId()]);

        header('Location: ' . adminUrl('reservation_detail.php') . '?id=' . $id . '&msg=sale_added');
        exit;
    }

    // 物販削除
    if ($action === 'delete_sale') {
        $db->prepare('DELETE FROM product_sales WHERE id=? AND reservation_id=?')->execute([(int)$_POST['sale_id'], $id]);
        header('Location: ' . adminUrl('reservation_detail.php') . '?id=' . $id . '&msg=sale_deleted');
        exit;
    }

    if ($action === 'use_coupon') {
        $code = trim($_POST['coupon_code'] ?? '');
        $stmt = $db->prepare('SELECT * FROM coupons WHERE code = ?');
        $stmt->execute([$code]);
        $coupon = $stmt->fetch();
        if (!$coupon) {
            $msg = '❌ クーポンが見つかりません';
        } elseif ($coupon['used_at']) {
            $msg = '❌ このクーポンは使用済みです（' . date('Y/m/d', strtotime($coupon['used_at'])) . '）';
        } elseif ($coupon['expired_at'] && strtotime($coupon['expired_at']) < time()) {
            $msg = '❌ このクーポンは有効期限切れです';
        } else {
            $db->prepare('UPDATE coupons SET used_at=NOW(), used_reservation_id=? WHERE id=?')
               ->execute([$id, $coupon['id']]);
            $msg = "✅ クーポン（{$coupon['description']} ¥" . number_format($coupon['discount']) . "）を使用済みにしました";
        }
    }
}

// 予約取得
$stmt = $db->prepare('
    SELECT r.*, c.name AS customer_name, c.id AS customer_id, c.line_user_id,
           c.phone, c.address, c.birthdate, c.furigana, c.gender, c.line_name,
           s.name AS staff_name, m.name AS menu_name, m.price AS menu_price
    FROM reservations r
    LEFT JOIN customers c ON r.customer_id = c.id
    LEFT JOIN staff s ON r.staff_id = s.id
    LEFT JOIN menus m ON r.menu_id = m.id
    WHERE r.id = ?
');
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) { header('Location: ' . adminUrl('reservations.php')); exit; }

// 使用クーポン
$usedCoupon = null;
$stmt = $db->prepare('SELECT * FROM coupons WHERE used_reservation_id = ?');
$stmt->execute([$id]);
$usedCoupon = $stmt->fetch();

$menus    = $db->query('SELECT * FROM menus WHERE is_active=1 ORDER BY display_order')->fetchAll();
$staffAll = $db->query('SELECT * FROM staff WHERE is_active=1 ORDER BY display_order')->fetchAll();

// 運勢機能チェック
$fortuneEnabled = false;
$fortuneData    = null;
try {
    $shopRow = $db->query('SELECT fortune_enabled FROM shop_settings WHERE id=1')->fetch();
    $fortuneEnabled = !empty($shopRow['fortune_enabled']);
} catch (Throwable $e) {}

// 当日予約の場合のみ運勢表示
$isToday = date('Y-m-d') === date('Y-m-d', strtotime($r['start_at']));

if ($fortuneEnabled && $isToday && !empty($r['birthdate'])) {
    // 星座計算
    $bm = (int)date('m', strtotime($r['birthdate']));
    $bd = (int)date('d', strtotime($r['birthdate']));
    $zodiacList = [
        [3,21,'牡羊座'],[4,20,'牡牛座'],[5,21,'双子座'],[6,21,'蟹座'],
        [7,23,'獅子座'],[8,23,'乙女座'],[9,23,'天秤座'],[10,23,'蠍座'],
        [11,22,'射手座'],[12,22,'山羊座'],[1,20,'水瓶座'],[2,19,'魚座'],
    ];
    $zodiac = '魚座';
    foreach ($zodiacList as [$zm,$zd,$zn]) {
        if (($bm==$zm && $bd>=$zd) || ($bm==$zm+1 && $bd<$zd)) { $zodiac=$zn; break; }
        if ($bm==3 && $bd<21) { $zodiac='魚座'; break; }
    }
    $fortuneData = ['zodiac' => $zodiac];
}
$products = $db->query('SELECT * FROM products WHERE is_active=1 ORDER BY category, display_order')->fetchAll();

// この予約の物販履歴
$salesStmt = $db->prepare('
    SELECT ps.*, p.name AS product_name, p.maker, p.category
    FROM product_sales ps
    JOIN products p ON ps.product_id = p.id
    WHERE ps.reservation_id = ?
    ORDER BY ps.sold_at DESC
');
$salesStmt->execute([$id]);
$sales = $salesStmt->fetchAll();
$statusLabels = ['pending'=>'仮予約','confirmed'=>'確定','completed'=>'完了','cancelled'=>'キャンセル'];

// 前回来店履歴（この予約を除く直近5件）
$prevStmt = $db->prepare('
    SELECT r.start_at, m.name AS menu_name, s.name AS staff_name, r.status
    FROM reservations r
    LEFT JOIN menus m ON r.menu_id = m.id
    LEFT JOIN staff s ON r.staff_id = s.id
    WHERE r.customer_id = ? AND r.id != ? AND r.status != "cancelled"
    ORDER BY r.start_at DESC
    LIMIT 5
');
$prevStmt->execute([$r['customer_id'], $id]);
$prevVisits = $prevStmt->fetchAll();

// 過去物販履歴（全予約含む直近10件）
$prevSalesStmt = $db->prepare('
    SELECT ps.sold_at, p.name AS product_name, ps.quantity, ps.price,
           s.name AS staff_name
    FROM product_sales ps
    JOIN products p ON ps.product_id = p.id
    LEFT JOIN staff s ON ps.created_by = s.id
    WHERE ps.customer_id = ?
    ORDER BY ps.sold_at DESC
    LIMIT 10
');
$prevSalesStmt->execute([$r['customer_id']]);
$prevSales = $prevSalesStmt->fetchAll();

// 会計データ取得
$receiptRow = $db->prepare('SELECT * FROM receipts WHERE reservation_id=?');
$receiptRow->execute([$id]);
$receiptRow = $receiptRow->fetch();
$receiptItems = [];
if ($receiptRow) {
    $riStmt = $db->prepare('SELECT * FROM receipt_items WHERE receipt_id=? ORDER BY id');
    $riStmt->execute([$receiptRow['id']]);
    $receiptItems = $riStmt->fetchAll();
}
// 未使用のクーポンに加え、QR等で先に「使用済み」になったが
// まだどの会計にも紐づいていないクーポンも選択肢に含める
// （会計画面を開く前にQRを使われても、ここで手動選択して割引を反映できるようにするため）
$availCouponsForRegister = $db->prepare('
    SELECT * FROM coupons
    WHERE customer_id = ?
      AND (
          (used_at IS NULL AND (expired_at IS NULL OR expired_at > NOW()))
          OR (used_at IS NOT NULL AND used_reservation_id IS NULL)
          OR used_reservation_id = ?
      )
    ORDER BY (used_at IS NOT NULL), issued_at DESC
');
$availCouponsForRegister->execute([$r['customer_id'], $id]);
$availCouponsForRegister = $availCouponsForRegister->fetchAll();

$pageTitle = '予約詳細 #' . $id;
include __DIR__ . '/_header.php';
?>

<?php
// 古いreceiptRow重複取得があれば無視（上で取得済み）
?>
<div class="page-title">
    <a href="<?= adminUrl('reservations.php') ?>" style="font-size:0.7em;font-weight:normal;">← 予約一覧</a><br>
    予約詳細 #<?= $id ?>
    <span class="badge badge-<?= h($r['status']) ?>" style="font-size:0.6em;vertical-align:middle;"><?= $statusLabels[$r['status']] ?? $r['status'] ?></span>
    <?php if ($receiptRow): ?>
    <span style="font-size:0.6em;vertical-align:middle;background:<?= $receiptRow['status']==='paid'?'#28a745':'#ffc107' ?>;color:<?= $receiptRow['status']==='paid'?'#fff':'#333' ?>;padding:2px 8px;border-radius:10px;margin-left:6px;">
        <?= $receiptRow['status']==='paid' ? '✅ 会計済み' : '💾 会計中' ?>
    </span>
    <?php endif; ?>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= str_starts_with($msg, '❌') ? 'danger' : 'success' ?>"><?= h($msg) ?></div>
<?php endif; ?>

<style>
@media (max-width: 768px) {
    .rd-main-grid    { grid-template-columns: 1fr !important; }
    .rd-form-grid    { grid-template-columns: 1fr !important; }
    .rd-summary-grid { grid-template-columns: 1fr !important; }
    /* レジ明細テーブル：列幅%指定をSPでは無効化して横スクロールに任せる */
    .rd-items-table th, .rd-items-table td { white-space: nowrap; }
}
</style>

<!-- ========================================================
  メインレイアウト：左1fr / 右2fr（SPでは1カラムに）
========================================================= -->
<div class="rd-main-grid" style="display:grid;grid-template-columns:1fr 2fr;gap:20px;align-items:start;">

<!-- ===== 左カラム ===== -->
<div style="display:flex;flex-direction:column;gap:16px;">

<!-- お客様情報 -->
<div class="card">
    <div class="card-header">
        お客様情報
        <div style="display:flex;gap:6px;">
            <?php if ($r['line_user_id']): ?>
            <button class="btn btn-sm" style="background:#00B900;color:#fff;" onclick="openLineModal('<?= h($r['line_user_id']) ?>','<?= h($r['customer_name']) ?>')">📱 LINE</button>
            <?php endif; ?>
            <a href="<?= adminUrl('customers.php') ?>?id=<?= $r['customer_id'] ?>" class="btn btn-sm btn-secondary">詳細</a>
        </div>
    </div>
    <div class="card-body">
        <table style="font-size:0.9em;width:100%;">
            <tr><td style="color:#888;padding:5px 0;width:70px;">名前</td><td><?= h($r['customer_name']) ?>様</td></tr>
            <tr><td style="color:#888;padding:5px 0;">ふりがな</td><td><?= h($r['furigana'] ?? '未登録') ?></td></tr>
            <tr><td style="color:#888;padding:5px 0;">LINE名</td><td><?= h($r['line_name'] ?? '未取得') ?></td></tr>
            <tr><td style="color:#888;padding:5px 0;">性別</td><td><?= ['male'=>'男性','female'=>'女性','other'=>'その他'][$r['gender'] ?? ''] ?? '未登録' ?></td></tr>
            <tr><td style="color:#888;padding:5px 0;">電話</td><td><?= h($r['phone'] ?? '未登録') ?></td></tr>
            <tr><td style="color:#888;padding:5px 0;">誕生日</td><td>
                <?php if ($r['birthdate']):
                    $age = (int)((strtotime('today') - strtotime($r['birthdate'])) / 86400 / 365.25);
                    echo h(date('Y/m/d', strtotime($r['birthdate']))) . '（' . $age . '歳）';
                else: echo '未登録'; endif; ?>
            </td></tr>
            <?php if ($r['address']): ?>
            <tr><td style="color:#888;padding:5px 0;">住所</td><td><a href="https://maps.google.com/?q=<?= urlencode($r['address']) ?>" target="_blank"><?= h($r['address']) ?> 🗺</a></td></tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- 予約内容 -->
<div class="card" id="reservationCard">
    <div class="card-header">
        予約内容
        <?php if ($fortuneEnabled && $isToday && $fortuneData): ?>
        <button class="btn btn-sm" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;" onclick="showFortune()">✨ 運勢</button>
        <?php endif; ?>
        <div>
            <button class="btn btn-sm btn-secondary view-only" onclick="setMode('reservationCard','edit')">✏️ 編集</button>
            <button class="btn btn-sm btn-secondary edit-only" onclick="setMode('reservationCard','view')">キャンセル</button>
        </div>
    </div>
    <div class="card-body">
        <div class="view-only">
            <table style="font-size:0.9em;width:100%;">
                <tr><td style="color:#888;padding:5px 0;width:60px;">日時</td><td><?php $dow=['日','月','火','水','木','金','土'][date('w',strtotime($r['start_at']))]; echo h(date('Y/m/d（'.$dow.'） H:i',strtotime($r['start_at']))); ?>〜<?= h(date('H:i',strtotime($r['end_at']))) ?></td></tr>
                <tr><td style="color:#888;padding:5px 0;">メニュー</td><td><?php
                    // 複数メニュー（LIFF予約）はスナップショットの内訳を表示
                    $rdSnap = json_decode($r['menu_snapshot'] ?? '', true) ?: [];
                    if (!empty($rdSnap['menus']) && is_array($rdSnap['menus'])) {
                        foreach ($rdSnap['menus'] as $rdM) {
                            echo h($rdM['name']) . '（¥' . number_format($rdM['price'] ?? 0) . '）<br>';
                        }
                        echo '<span style="color:#888;font-size:0.85em;">合計 約' . (int)($rdSnap['duration_min'] ?? 0) . '分 / ¥' . number_format($rdSnap['price'] ?? 0) . '</span>';
                    } else {
                        echo h($r['menu_name'] ?? '-') . '（¥' . number_format($r['menu_price'] ?? 0) . '）';
                    }
                ?></td></tr>
                <tr><td style="color:#888;padding:5px 0;">担当</td><td><?= h($r['staff_name'] ?? '指名なし') ?></td></tr>
                <tr><td style="color:#888;padding:5px 0;">備考</td><td style="font-size:0.88em;"><?= nl2br(h($r['note'] ?? '-')) ?></td></tr>
            </table>
        </div>
        <form method="post" class="edit-only">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update">
            <div class="rd-form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="form-group"><label>日付</label><input type="date" name="date" value="<?= h(date('Y-m-d', strtotime($r['start_at']))) ?>" required></div>
                <div class="form-group"><label>開始時間</label><input type="time" name="time" value="<?= h(date('H:i', strtotime($r['start_at']))) ?>" required></div>
            </div>
            <div class="form-group"><label>メニュー</label>
                <select name="menu_id" required>
                    <?php foreach ($menus as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $r['menu_id']==$m['id']?'selected':'' ?>><?= h($m['name']) ?>（<?= $m['duration_min'] ?>分）</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>担当スタッフ</label>
                <select name="staff_id">
                    <option value="">指名なし</option>
                    <?php foreach ($staffAll as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $r['staff_id']==$s['id']?'selected':'' ?>><?= h($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>備考</label><textarea name="note" rows="2"><?= h($r['note'] ?? '') ?></textarea></div>
            <button class="btn btn-primary btn-sm" type="submit">更新</button>
        </form>
    </div>
</div>

<!-- 受付情報 -->
<div class="card">
    <div class="card-header">受付情報</div>
    <div class="card-body">
        <table style="font-size:0.88em;width:100%;">
            <tr><td style="color:#888;width:70px;padding:4px 0;">受付日時</td><td><?= h(date('Y/m/d H:i', strtotime($r['created_at']))) ?></td></tr>
            <tr><td style="color:#888;padding:4px 0;">予約ID</td><td>#<?= $r['id'] ?></td></tr>
        </table>
    </div>
</div>

<!-- ステータス変更 -->
<div class="card">
    <div class="card-header">ステータス変更</div>
    <div class="card-body" style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach (['confirmed'=>['primary','✅ 確定'],'completed'=>['secondary','完了'],'cancelled'=>['danger','✗ キャンセル']] as $st => [$cls, $label]): ?>
        <?php if ($r['status'] !== $st): ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="status" value="<?= $st ?>">
            <button class="btn btn-<?= $cls ?> btn-sm" type="submit" <?php if($st==='cancelled') echo 'onclick="return confirm(\'キャンセルしますか？\')"'; ?>><?= $label ?></button>
        </form>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- /左カラム -->

<!-- ===== 右カラム ===== -->
<div style="display:flex;flex-direction:column;gap:16px;">

<!-- ============================================================ -->
<!-- 💰 レジ・会計 -->
<!-- ============================================================ -->
<?php $regPaid = $receiptRow && $receiptRow['status'] === 'paid'; ?>
<div class="card" id="registerCard">
    <?php
    $regBg = '#f0f7f4'; // デフォルト：薄緑
    if ($regPaid) $regBg = '#fce4ec'; // 会計済み：薄ピンク
    if ($r['status'] === 'cancelled') $regBg = '#e3f2fd'; // キャンセル：薄青
    ?>
    <div class="card-header" style="background:<?= $regBg ?>;">
        <div>
            💰 レジ・会計
            <?php if ($regPaid): ?>
            <span style="background:#e91e63;color:#fff;padding:2px 10px;border-radius:10px;font-size:0.78em;">✅ 会計確定済み</span>
            <?php endif; ?>
            <?php if ($r['status'] === 'cancelled'): ?>
            <span style="background:#1976d2;color:#fff;padding:2px 10px;border-radius:10px;font-size:0.78em;">キャンセル</span>
            <?php endif; ?>
        </div>
        <?php if ($regPaid): ?>
        <div>
            <button class="btn btn-sm btn-secondary view-only" onclick="unlockRegisterEdit()">✏️ 編集する</button>
            <button class="btn btn-sm btn-secondary edit-only" onclick="cancelRegisterEdit()">キャンセル</button>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body" style="padding:0;">

        <!-- 明細テーブル -->
        <table class="rd-items-table" style="width:100%;border-collapse:collapse;font-size:0.9em;">
            <thead>
                <tr style="background:#f8fdf8;">
                    <th style="padding:8px 10px;text-align:left;width:30%;">種別</th>
                    <th style="padding:8px 10px;text-align:left;width:30%;">項目</th>
                    <th style="padding:8px 10px;text-align:right;width:15%;">単価</th>
                    <th style="padding:8px 10px;text-align:center;width:10%;">数量</th>
                    <th style="padding:8px 10px;text-align:right;width:12%;">小計</th>
                    <th style="padding:8px 10px;width:32px;"></th>
                </tr>
            </thead>
            <tbody id="regItemsBody"><!-- JSで描画 --></tbody>
        </table>

        <!-- 行追加ボタン -->
        <div class="edit-only" style="padding:10px 12px;border-top:1px solid #eee;">
            <button class="btn btn-sm btn-secondary" onclick="regAddRow()" style="font-size:0.85em;">＋ 行を追加</button>
        </div>

        <!-- クーポン・値引き・合計エリア -->
        <div style="border-top:2px solid #e8f0ed;padding:14px 16px;background:<?= $regBg ?>;">
            <div class="rd-summary-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <!-- 左：クーポン・値引き・支払い -->
                <div>
                    <div class="edit-only">
                    <div style="margin-bottom:10px;">
                        <label style="font-size:0.83em;color:#666;display:block;margin-bottom:3px;">🎫 クーポン</label>
                        <select id="regCoupon" onchange="regCalc()" style="width:100%;padding:5px 8px;border:1px solid #ddd;border-radius:6px;font-size:0.85em;">
                            <option value="">使用しない</option>
                            <?php
                            // 会計にまだクーポンが選ばれていなければ、QR等で使用済み・未紐付けのものを自動で仮選択
                            $cpAutoSelectId = null;
                            if (!$receiptRow || !$receiptRow['coupon_id']) {
                                foreach ($availCouponsForRegister as $cp) {
                                    if ($cp['used_at'] && !$cp['used_reservation_id']) { $cpAutoSelectId = $cp['id']; break; }
                                }
                            }
                            foreach ($availCouponsForRegister as $cp):
                                $cpIsPercent  = ($cp['discount_type'] ?? 'amount') === 'percent';
                                $cpLabel      = $cpIsPercent ? ($cp['discount_rate'] . '% OFF') : ('-¥' . number_format($cp['discount']));
                                $cpUsedUnlinked = $cp['used_at'] && !$cp['used_reservation_id'];
                                $cpSelected   = ($receiptRow && $receiptRow['coupon_id'] == $cp['id']) || ((!$receiptRow || !$receiptRow['coupon_id']) && $cp['id'] == $cpAutoSelectId);
                            ?>
                            <option value="<?= $cp['id'] ?>"
                                data-amount="<?= $cp['discount'] ?>"
                                data-type="<?= h($cp['discount_type'] ?? 'amount') ?>"
                                data-rate="<?= (int)($cp['discount_rate'] ?? 0) ?>"
                                <?= $cpSelected ? 'selected' : '' ?>>
                                <?= h($cp['description']) ?> <?= $cpLabel ?><?= $cpUsedUnlinked ? '（使用済み・QR照合済み）' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:10px;">
                        <label style="font-size:0.83em;color:#666;display:block;margin-bottom:3px;">💴 値引き（円）</label>
                        <input type="number" id="regDiscount" value="<?= (int)($receiptRow['discount_amount'] ?? 0) ?>" min="0"
                               oninput="regCalc()" style="width:100%;padding:5px 8px;border:1px solid #ddd;border-radius:6px;">
                    </div>
                    <div>
                        <label style="font-size:0.83em;color:#666;display:block;margin-bottom:3px;">💳 支払い方法</label>
                        <select id="regPayment" style="width:100%;padding:5px 8px;border:1px solid #ddd;border-radius:6px;">
                            <option value="cash"    <?= ($receiptRow['payment_method']??'cash')==='cash'    ?'selected':'' ?>>💴 現金</option>
                            <option value="card"    <?= ($receiptRow['payment_method']??'')==='card'    ?'selected':'' ?>>💳 カード</option>
                            <option value="paypay"  <?= ($receiptRow['payment_method']??'')==='paypay'  ?'selected':'' ?>>📱 PayPay</option>
                            <option value="line_pay"<?= ($receiptRow['payment_method']??'')==='line_pay'?'selected':'' ?>>💚 LINE Pay</option>
                            <option value="other"   <?= ($receiptRow['payment_method']??'')==='other'   ?'selected':'' ?>>その他</option>
                        </select>
                    </div>
                    </div>
                    <?php if ($regPaid): ?>
                    <div class="view-only">
                    <table style="font-size:0.88em;width:100%;">
                        <tr><td style="color:#888;padding:3px 0;">支払方法</td><td><?= ['cash'=>'💴 現金','card'=>'💳 カード','paypay'=>'📱 PayPay','line_pay'=>'💚 LINE Pay','other'=>'その他'][$receiptRow['payment_method']] ?? '-' ?></td></tr>
                        <?php if ($receiptRow['coupon_amount']): ?><tr><td style="color:#888;padding:3px 0;">クーポン</td><td>-¥<?= number_format($receiptRow['coupon_amount']) ?></td></tr><?php endif; ?>
                        <?php if ($receiptRow['discount_amount']): ?><tr><td style="color:#888;padding:3px 0;">値引き</td><td>-¥<?= number_format($receiptRow['discount_amount']) ?></td></tr><?php endif; ?>
                        <tr><td style="color:#888;padding:3px 0;">確定日時</td><td><?= date('Y/m/d H:i', strtotime($receiptRow['confirmed_at'])) ?></td></tr>
                    </table>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- 右：合計 -->
                <div style="display:flex;flex-direction:column;justify-content:space-between;">
                    <div>
                        <?php if ($regPaid): ?>
                        <div class="view-only">
                            <div style="display:flex;justify-content:space-between;font-size:0.88em;padding:3px 0;"><span style="color:#888;">小計</span><span>¥<?= number_format($receiptRow['subtotal']) ?></span></div>
                            <div style="display:flex;justify-content:space-between;font-size:0.88em;padding:3px 0;"><span style="color:#888;">消費税（内税）</span><span>¥<?= number_format($receiptRow['tax_amount']) ?></span></div>
                            <div style="display:flex;justify-content:space-between;font-size:0.88em;padding:3px 0;"><span style="color:#888;">クーポン</span><span style="color:#e74c3c;">-¥<?= number_format($receiptRow['coupon_amount']) ?></span></div>
                            <div style="display:flex;justify-content:space-between;font-size:0.88em;padding:3px 0;"><span style="color:#888;">値引き</span><span style="color:#e74c3c;">-¥<?= number_format($receiptRow['discount_amount']) ?></span></div>
                            <div style="display:flex;justify-content:space-between;font-size:1.2em;font-weight:bold;padding:8px 0 0;border-top:2px solid #2d7a5f;margin-top:6px;"><span>合計</span><span style="color:#2d7a5f;">¥<?= number_format($receiptRow['total']) ?></span></div>
                        </div>
                        <?php endif; ?>
                        <div class="edit-only">
                            <div style="display:flex;justify-content:space-between;font-size:0.88em;padding:3px 0;"><span style="color:#888;">小計</span><span id="regSumSubtotal">¥0</span></div>
                            <div style="display:flex;justify-content:space-between;font-size:0.88em;padding:3px 0;"><span style="color:#888;">消費税（内税）</span><span id="regSumTax">¥0</span></div>
                            <div style="display:flex;justify-content:space-between;font-size:0.88em;padding:3px 0;"><span style="color:#888;">クーポン</span><span id="regSumCoupon" style="color:#e74c3c;">-¥0</span></div>
                            <div style="display:flex;justify-content:space-between;font-size:0.88em;padding:3px 0;"><span style="color:#888;">値引き</span><span id="regSumDiscount" style="color:#e74c3c;">-¥0</span></div>
                            <div style="display:flex;justify-content:space-between;font-size:1.2em;font-weight:bold;padding:8px 0 0;border-top:2px solid #2d7a5f;margin-top:6px;"><span>合計</span><span id="regSumTotal" style="color:#2d7a5f;">¥0</span></div>
                        </div>
                    </div>
                    <div class="edit-only" style="margin-top:12px;display:flex;flex-direction:column;gap:6px;">
                        <button class="btn" style="background:#2d7a5f;color:#fff;width:100%;padding:9px;font-weight:bold;" onclick="regSave('pay')"><?= $regPaid ? '✅ 変更を保存して再確定' : '✅ 会計確定' ?></button>
                        <button class="btn btn-secondary" style="width:100%;padding:7px;" onclick="regSave('save')">💾 一時保存</button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- 前回来店履歴 -->
<div class="card">
    <div class="card-header">📋 前回来店履歴</div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($prevVisits)): ?>
        <p style="padding:14px 16px;color:#aaa;font-size:0.9em;margin:0;">来店履歴がありません</p>
        <?php else: ?>
        <table style="width:100%;font-size:0.88em;border-collapse:collapse;">
            <tr style="background:#f8f9fa;"><th style="padding:7px 12px;font-weight:600;text-align:left;">日付</th><th style="padding:7px 12px;font-weight:600;text-align:left;">メニュー</th><th style="padding:7px 12px;font-weight:600;text-align:left;">担当</th></tr>
            <?php foreach ($prevVisits as $pv): ?>
            <tr style="border-bottom:1px solid #f0f0f0;">
                <td style="padding:7px 12px;white-space:nowrap;"><?= date('Y/m/d', strtotime($pv['start_at'])) ?></td>
                <td style="padding:7px 12px;"><?= h($pv['menu_name'] ?? '-') ?></td>
                <td style="padding:7px 12px;color:#888;"><?= h($pv['staff_name'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- 物販履歴 -->
<div class="card">
    <div class="card-header">🛍 物販履歴</div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($prevSales)): ?>
        <p style="padding:14px 16px;color:#aaa;font-size:0.9em;margin:0;">購入履歴がありません</p>
        <?php else: ?>
        <table style="width:100%;font-size:0.88em;border-collapse:collapse;">
            <tr style="background:#f8f9fa;"><th style="padding:7px 12px;font-weight:600;text-align:left;">日付</th><th style="padding:7px 12px;font-weight:600;text-align:left;">商品</th><th style="padding:7px 12px;font-weight:600;text-align:center;">数量</th><th style="padding:7px 12px;font-weight:600;text-align:right;">金額</th><th style="padding:7px 12px;font-weight:600;text-align:left;">担当</th></tr>
            <?php foreach ($prevSales as $ps): ?>
            <tr style="border-bottom:1px solid #f0f0f0;">
                <td style="padding:7px 12px;white-space:nowrap;"><?= date('Y/m/d', strtotime($ps['sold_at'])) ?></td>
                <td style="padding:7px 12px;"><?= h($ps['product_name']) ?></td>
                <td style="padding:7px 12px;text-align:center;"><?= $ps['quantity'] ?>個</td>
                <td style="padding:7px 12px;text-align:right;">¥<?= number_format($ps['price']) ?></td>
                <td style="padding:7px 12px;color:#888;"><?= h($ps['staff_name'] ?? '-') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </div>
</div>

</div><!-- /右カラム -->
</div><!-- /grid -->

<!-- 運勢モーダル -->
<?php if ($fortuneEnabled && $isToday && $fortuneData): ?>
<div id="fortuneModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9998;align-items:center;justify-content:center;">
    <div style="background:linear-gradient(135deg,#1a1a2e,#16213e,#0f3460);border-radius:16px;width:420px;box-shadow:0 20px 60px rgba(0,0,0,0.5);overflow:hidden;">
        <div style="padding:20px 24px;text-align:center;border-bottom:1px solid rgba(255,255,255,0.1);">
            <div style="font-size:2em;margin-bottom:4px;" id="fortuneZodiacIcon"></div>
            <div style="color:#fff;font-size:1.1em;font-weight:bold;" id="fortuneTitle"></div>
            <div style="color:#aaa;font-size:0.85em;margin-top:2px;"><?= date('Y年m月d日') ?>の運勢</div>
        </div>
        <div style="padding:20px 24px;" id="fortuneContent">
            <div style="text-align:center;color:#aaa;padding:20px;">✨ 占っています...</div>
        </div>
        <div style="padding:12px 24px;text-align:center;border-top:1px solid rgba(255,255,255,0.1);">
            <button onclick="closeFortune()" style="background:#6B9E8A;color:#fff;border:none;padding:8px 24px;border-radius:20px;cursor:pointer;">閉じる ✨</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- お客様詳細モーダル -->
<div id="customerModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;width:560px;max-height:85vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="padding:16px 20px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
            <strong>お客様詳細</strong>
            <button onclick="closeCustomerModal()" style="border:none;background:none;font-size:1.4em;cursor:pointer;color:#888;">✕</button>
        </div>
        <div id="customerModalContent" style="padding:20px;">読み込み中...</div>
    </div>
</div>

<!-- LINE送信モーダル -->
<div id="lineModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;width:500px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="padding:16px 20px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
            <strong>📱 LINEメッセージ送信</strong>
            <button onclick="closeLineModal()" style="border:none;background:none;font-size:1.4em;cursor:pointer;color:#888;">✕</button>
        </div>
        <div style="padding:20px;">
            <div style="margin-bottom:12px;color:#888;font-size:0.9em;">送信先：<span id="lineModalName" style="color:#333;font-weight:bold;"></span>様</div>
            <div class="form-group"><label>メッセージ</label><textarea id="lineModalText" rows="5" style="width:100%;"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="btn btn-secondary" onclick="closeLineModal()">キャンセル</button>
                <button class="btn" style="background:#00B900;color:#fff;" onclick="sendLineMessage()">📱 送信する</button>
            </div>
            <div id="lineModalResult" style="margin-top:10px;"></div>
        </div>
    </div>
</div>

<script>
// ============================================================
// レジ機能
// ============================================================
const REG_RESERVATION_ID = <?= $id ?>;
const REG_CSRF           = '<?= csrf() ?>';
let REG_IS_PAID          = <?= ($receiptRow && $receiptRow['status']==='paid') ? 'true' : 'false' ?>;
const REG_WAS_PAID       = REG_IS_PAID; // 元々会計確定済みだったか（キャンセル時の復元用）

// 会計確定済みの内容を再編集できるようにする
function unlockRegisterEdit() {
    if (!confirm('会計確定済みの内容を編集します。保存し直すまでは確定されません。よろしいですか？')) return;
    REG_IS_PAID = false;
    setMode('registerCard', 'edit');
    regRenderItems();
    regCalc();
}
function cancelRegisterEdit() {
    if (REG_WAS_PAID) {
        location.reload(); // 未保存の変更は破棄して元の確定内容に戻す
        return;
    }
    REG_IS_PAID = false;
    setMode('registerCard', 'edit');
}
let regAllMenus       = [];
let regAllProducts    = [];
let regItems          = [];
let regMenuComponents = {};
let regStaffPrices    = {};
let regNominationFee  = 0;

// メニューをセット分解して追加
function regExpandMenu(menuId) {
    const components = regMenuComponents[menuId];
    if (components && components.length > 0) {
        // セット構成あり→子メニューを展開
        components.forEach(childId => {
            const menu = regAllMenus.find(m => m.id === childId);
            if (menu) {
                const price = regStaffPrices[childId] !== undefined ? regStaffPrices[childId] : menu.price;
                regItems.push({
                    item_type:'menu', item_id:menu.id,
                    item_name:menu.name, unit_price:price,
                    quantity:1, tax_rate:0.10, discount:0
                });
            }
        });
    } else {
        // セット構成なし→そのまま追加
        const menu = regAllMenus.find(m => m.id === menuId);
        if (menu) {
            const price = regStaffPrices[menuId] !== undefined ? regStaffPrices[menuId] : menu.price;
            regItems.push({
                item_type:'menu', item_id:menu.id,
                item_name:menu.name, unit_price:price,
                quantity:1, tax_rate:0.10, discount:0
            });
        }
    }
}

<?php
$regItemsJson = json_encode(array_map(function($i) {
    return [
        'item_type'  => $i['item_type'],
        'item_id'    => (int)$i['item_id'],
        'item_name'  => $i['item_name'],
        'unit_price' => (int)$i['unit_price'],
        'quantity'   => (int)$i['quantity'],
        'tax_rate'   => (float)$i['tax_rate'],
        'discount'   => (int)$i['discount'],
    ];
}, $receiptItems), JSON_UNESCAPED_UNICODE);
?>
const regExistingItems = <?= $regItemsJson ?>;

document.addEventListener('DOMContentLoaded', function() {
fetch('<?= adminUrl('api/get_items.php') ?>?staff_id=<?= (int)($r['staff_id'] ?? 0) ?>')
    .then(r => r.json())
    .then(data => {
        regAllMenus        = data.menus;
        regAllProducts     = data.products;
        regMenuComponents  = data.menu_components || {};
        regStaffPrices     = data.staff_prices || {};
        regNominationFee   = data.nomination_fee || 0;

        if (regExistingItems.length > 0) {
            regItems = regExistingItems;
        } else {
            <?php
            // 複数メニュー（LIFF予約）はスナップショットの全メニューをレジに展開
            $regSnap    = json_decode($r['menu_snapshot'] ?? '', true) ?: [];
            $regMenuIds = [];
            if (!empty($regSnap['menu_ids']) && is_array($regSnap['menu_ids'])) {
                $regMenuIds = array_map('intval', $regSnap['menu_ids']);
            } elseif ($r['menu_id']) {
                $regMenuIds = [(int)$r['menu_id']];
            }
            ?>
            const defaultMenuIds = <?= json_encode($regMenuIds) ?>;
            // セット構成があれば分解、なければそのまま
            defaultMenuIds.forEach(id => regExpandMenu(id));

            // 指名料（0円は追加しない）
            if (regNominationFee > 0) {
                regItems.push({
                    item_type:'nomination', item_id:0,
                    item_name:'指名料（<?= addslashes($r['staff_name'] ?? '') ?>）',
                    unit_price:regNominationFee,
                    quantity:1, tax_rate:0.10, discount:0
                });
            }
        }
        regRenderItems();
        regCalc();
    });
}); // DOMContentLoaded

function regGetList(type) {
    return type === 'menu' ? regAllMenus : regAllProducts;
}

function regAddRow() {
    // 追加行は空のプレースホルダー（種別・項目未選択）
    regItems.push({
        item_type:'menu',
        item_id: 0,
        item_name: '',
        unit_price: 0,
        quantity:1, tax_rate:0.10, discount:0
    });
    regRenderItems();
    regCalc();
}

function regRemoveItem(idx) {
    regItems.splice(idx, 1);
    regRenderItems();
    regCalc();
}

function regChangeType(idx, type) {
    if (type === 'nomination') return; // 指名料は変更不可
    const list = regGetList(type);
    regItems[idx] = {
        item_type: type, item_id: 0, item_name: '',
        unit_price: 0, quantity: regItems[idx].quantity,
        tax_rate: 0.10, discount: 0
    };
    regRenderItems();
    regCalc();
}

function regChangeItem(idx, itemId) {
    const type = regItems[idx].item_type;
    const list = regGetList(type);
    const found = list.find(i => i.id == itemId);
    if (found) {
        regItems[idx].item_id    = found.id;
        regItems[idx].item_name  = found.name;
        regItems[idx].unit_price = found.price;
        regItems[idx].tax_rate   = type==='menu' ? 0.10 : (found.tax_rate||0.10);
    } else {
        regItems[idx].item_id    = 0;
        regItems[idx].item_name  = '';
        regItems[idx].unit_price = 0;
    }
    regRenderItems();
    regCalc();
}

function regRenderItems() {
    const body = document.getElementById('regItemsBody');
    if (regItems.length === 0) {
        body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:#aaa;">明細がありません</td></tr>';
        return;
    }
    body.innerHTML = regItems.map((item, idx) => {
        const sub = (item.unit_price * item.quantity) - item.discount;
        const typeOpts = item.item_type === 'nomination'
            ? `<option value="nomination" selected>指名料</option>`
            : ['menu','product'].map(t =>
                `<option value="${t}" ${item.item_type===t?'selected':''}>${t==='menu'?'施術':'物販'}</option>`
              ).join('');
        // 指名料の場合は項目列にスタッフ名を表示
        const isNomination = item.item_type === 'nomination';
        const list = regGetList(item.item_type);
        const emptyOpt = item.item_id === 0 && !isNomination ? '<option value="0">-- 選択 --</option>' : '';
        const itemOpts = emptyOpt + list.map(i =>
            `<option value="${i.id}" ${i.id===item.item_id?'selected':''}>${regEscHtml(i.name)}</option>`
        ).join('');
        const disabled = REG_IS_PAID ? 'disabled' : '';
        return `<tr style="border-bottom:1px solid #f0f0f0;">
            <td style="padding:6px 8px;">
                <select ${disabled} onchange="regChangeType(${idx},this.value)"
                    style="padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-size:0.85em;width:70px;">${typeOpts}</select>
            </td>
            <td style="padding:6px 8px;">
                ${isNomination
                    ? `<span style="font-size:0.9em;color:#555;">${regEscHtml(item.item_name)}</span>`
                    : `<select ${disabled} onchange="regChangeItem(${idx},this.value)"
                        style="padding:4px 6px;border:1px solid #ddd;border-radius:4px;font-size:0.85em;width:100%;max-width:200px;">${itemOpts}</select>`
                }
            </td>
            <td style="padding:6px 8px;text-align:right;font-size:0.9em;">¥${item.unit_price.toLocaleString()}</td>
            <td style="padding:6px 8px;text-align:center;">
                <input type="number" value="${item.quantity}" min="1" ${disabled}
                    onchange="regItems[${idx}].quantity=parseInt(this.value)||1;regRenderItems();regCalc();"
                    style="width:50px;padding:3px;border:1px solid #ddd;border-radius:4px;text-align:center;">
            </td>
            <td style="padding:6px 8px;text-align:right;font-weight:bold;">¥${sub.toLocaleString()}</td>
            ${!REG_IS_PAID ? `<td style="padding:6px 4px;"><button onclick="regRemoveItem(${idx})" style="background:none;border:none;color:#e74c3c;cursor:pointer;font-size:1.1em;line-height:1;">✕</button></td>` : '<td></td>'}
        </tr>`;
    }).join('');
}

function regCalc() {
    let subtotal = 0, tax = 0;
    regItems.forEach(item => {
        const s = (item.unit_price * item.quantity) - item.discount;
        subtotal += s;
        tax      += Math.round(s * item.tax_rate / (1 + item.tax_rate));
    });
    const couponEl  = document.getElementById('regCoupon');
    let couponAmt   = 0;
    if (couponEl && couponEl.selectedIndex > 0) {
        const opt = couponEl.options[couponEl.selectedIndex];
        couponAmt = opt.dataset.type === 'percent'
            ? Math.round(subtotal * (parseInt(opt.dataset.rate) || 0) / 100)
            : (parseInt(opt.dataset.amount) || 0);
    }
    const discount  = parseInt(document.getElementById('regDiscount')?.value) || 0;
    const total     = Math.max(0, subtotal - couponAmt - discount);
    document.getElementById('regSumSubtotal').textContent = '¥' + subtotal.toLocaleString();
    document.getElementById('regSumTax').textContent      = '¥' + tax.toLocaleString();
    document.getElementById('regSumCoupon').textContent   = '-¥' + couponAmt.toLocaleString();
    document.getElementById('regSumDiscount').textContent = '-¥' + discount.toLocaleString();
    document.getElementById('regSumTotal').textContent    = '¥' + total.toLocaleString();
}

function regSave(action) {
    if (action === 'pay' && !confirm('会計を確定します。よろしいですか？')) return;
    const couponEl = document.getElementById('regCoupon');
    const couponId = couponEl && couponEl.value ? parseInt(couponEl.value) : null;
    fetch('<?= adminUrl('api/save_receipt.php') ?>', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({
            reservation_id : REG_RESERVATION_ID,
            items          : regItems,
            discount_amount: parseInt(document.getElementById('regDiscount')?.value) || 0,
            coupon_id      : couponId,
            payment_method : document.getElementById('regPayment')?.value || 'cash',
            note           : '',
            action         : action,
            csrf_token     : REG_CSRF,
        }),
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert(action === 'pay' ? '✅ 会計を確定しました！' : '💾 一時保存しました');
            location.reload();
        } else {
            alert('❌ ' + (data.error || '保存に失敗しました'));
        }
    });
}

function regEscHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ============================================================
// クーポン自動反映ポーリング
// ============================================================
<?php if (!$receiptRow || $receiptRow['status'] !== 'paid'): ?>
(function() {
    const CUSTOMER_ID    = <?= (int)$r['customer_id'] ?>;
    const RESERVATION_ID = <?= $id ?>;
    let pollingTimer     = null;
    let lastCouponId     = null;

    function startCouponPolling() {
        pollingTimer = setInterval(function() {
            fetch('<?= adminUrl('api/get_coupon_status.php') ?>?customer_id=' + CUSTOMER_ID + '&reservation_id=' + RESERVATION_ID)
                .then(r => r.json())
                .then(data => {
                    if (data.coupon && data.coupon.id !== lastCouponId) {
                        lastCouponId = data.coupon.id;
                        applyCouponToRegister(data.coupon);
                    }
                })
                .catch(() => {});
        }, 4000); // 4秒ごとにポーリング
    }

    function applyCouponToRegister(coupon) {
        // クーポンプルダウンに選択肢を追加して自動選択
        const sel = document.getElementById('regCoupon');
        if (!sel) return;

        // 既存オプションをチェック
        let found = false;
        for (let opt of sel.options) {
            if (parseInt(opt.value) === coupon.id) {
                sel.value = coupon.id;
                found = true;
                break;
            }
        }

        // なければ追加して選択
        if (!found) {
            const isPercent = coupon.discount_type === 'percent';
            const opt = document.createElement('option');
            opt.value = coupon.id;
            opt.dataset.amount = coupon.discount;
            opt.dataset.type   = coupon.discount_type || 'amount';
            opt.dataset.rate   = coupon.discount_rate || 0;
            const label = isPercent ? (coupon.discount_rate + '% OFF') : ('-¥' + coupon.discount.toLocaleString());
            opt.textContent = coupon.description + ' ' + label + '（' + coupon.code + '）';
            sel.appendChild(opt);
            sel.value = coupon.id;
        }

        regCalc();

        // 通知表示
        showCouponNotice(coupon);
    }

    function showCouponNotice(coupon) {
        // 既存の通知があれば削除
        const old = document.getElementById('couponAutoNotice');
        if (old) old.remove();

        const div = document.createElement('div');
        div.id = 'couponAutoNotice';
        div.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;background:#28a745;color:#fff;padding:14px 20px;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,0.2);font-weight:bold;font-size:0.95em;';
        div.innerHTML = '🎫 クーポンが適用されました！<br><span style="font-size:0.85em;font-weight:normal;">' + coupon.description + ' -¥' + coupon.discount.toLocaleString() + '</span>';
        document.body.appendChild(div);
        setTimeout(() => div.remove(), 4000);
    }

    // ページ読み込み後にポーリング開始
    document.addEventListener('DOMContentLoaded', startCouponPolling);
})();
<?php endif; ?>

// ============================================================
// 運勢
// ============================================================
<?php if ($fortuneEnabled && $isToday && $fortuneData): ?>
const fortuneZodiac   = '<?= h($fortuneData["zodiac"]) ?>';
const fortuneCustomer = '<?= h($r["customer_name"] ?? "お客様") ?>';
const zodiacIcons = {'牡羊座':'♈','牡牛座':'♉','双子座':'♊','蟹座':'♋','獅子座':'♌','乙女座':'♍','天秤座':'♎','蠍座':'♏','射手座':'♐','山羊座':'♑','水瓶座':'♒','魚座':'♓'};
function closeFortune() { document.getElementById('fortuneModal').style.display='none'; }
function showFortune() {
    const modal = document.getElementById('fortuneModal');
    document.getElementById('fortuneZodiacIcon').textContent = zodiacIcons[fortuneZodiac] || '⭐';
    document.getElementById('fortuneTitle').textContent = fortuneCustomer + '様（' + fortuneZodiac + '）';
    modal.style.display = 'flex';
    fetch('<?= adminUrl('fortune.php') ?>?zodiac=' + encodeURIComponent(fortuneZodiac) + '&customer=' + encodeURIComponent(fortuneCustomer))
    .then(r => r.json()).then(fortune => {
        const stars = '⭐'.repeat(Math.round((fortune.score||70)/20));
        document.getElementById('fortuneContent').innerHTML = `
            <div style="text-align:center;margin-bottom:16px;">
                <div style="font-size:1.8em;">${stars}</div>
                <div style="color:#ffd700;font-size:0.9em;">${fortune.score||70}点</div>
            </div>
            <div style="color:#ddd;font-size:0.92em;line-height:1.7;margin-bottom:16px;padding:12px;background:rgba(255,255,255,0.05);border-radius:8px;">${fortune.message||''}</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
                <div style="background:rgba(255,255,255,0.05);border-radius:8px;padding:12px;text-align:center;">
                    <div style="font-size:0.75em;color:#aaa;margin-bottom:4px;">🎨 ラッキーカラー</div>
                    <div style="display:flex;align-items:center;justify-content:center;gap:6px;">
                        <span style="width:16px;height:16px;border-radius:50%;background:${fortune.lucky_color_hex||'#6B9E8A'};display:inline-block;"></span>
                        <span style="color:#fff;font-weight:bold;font-size:0.9em;">${fortune.lucky_color||''}</span>
                    </div>
                </div>
                <div style="background:rgba(255,255,255,0.05);border-radius:8px;padding:12px;text-align:center;">
                    <div style="font-size:0.75em;color:#aaa;margin-bottom:4px;">✨ ラッキーアイテム</div>
                    <div style="color:#fff;font-weight:bold;font-size:0.9em;">${fortune.lucky_item_emoji||''} ${fortune.lucky_item||''}</div>
                </div>
            </div>
            <div style="background:rgba(107,158,138,0.2);border-radius:8px;padding:10px 12px;font-size:0.85em;color:#a8d5c2;border-left:3px solid #6B9E8A;">✂️ ${fortune.advice||''}</div>`;
    }).catch(() => {
        document.getElementById('fortuneContent').innerHTML = '<div style="text-align:center;color:#aaa;padding:20px;">運勢の取得に失敗しました</div>';
    });
}
document.addEventListener('DOMContentLoaded', () => setTimeout(showFortune, 500));
<?php endif; ?>

// ============================================================
// モーダル・その他
// ============================================================
function setMode(cardId, mode) {
    const card = document.getElementById(cardId);
    card.classList.remove('view-mode','edit-mode');
    card.classList.add(mode + '-mode');
}
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.card').forEach(c => c.classList.add('view-mode'));
    // レジ・会計カードのみ：未会計確定なら初期状態を編集モードにする
    if (!REG_IS_PAID) setMode('registerCard', 'edit');
});
function openCustomerModal(customerId) {
    document.getElementById('customerModal').style.display = 'flex';
    fetch('<?= adminUrl('customer_modal.php') ?>?id=' + customerId)
        .then(r => r.text())
        .then(html => { document.getElementById('customerModalContent').innerHTML = html; });
}
function closeCustomerModal() { document.getElementById('customerModal').style.display = 'none'; }
document.getElementById('customerModal').addEventListener('click', function(e) { if (e.target===this) closeCustomerModal(); });

let currentLineUserId = '';
function openLineModal(id, name) {
    currentLineUserId = id;
    document.getElementById('lineModalName').textContent = name;
    document.getElementById('lineModalResult').innerHTML = '';
    document.getElementById('lineModalText').value = name + '様\n\nいつもご来店ありがとうございます✨\n';
    const m = document.getElementById('lineModal');
    m.style.display = 'flex'; m.style.alignItems = 'center'; m.style.justifyContent = 'center';
}
function closeLineModal() { document.getElementById('lineModal').style.display = 'none'; }
function sendLineMessage() {
    const text = document.getElementById('lineModalText').value.trim();
    if (!text) { alert('メッセージを入力してください'); return; }
    fetch('<?= adminUrl('send_line.php') ?>', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ line_user_id: currentLineUserId, message: text, csrf_token: '<?= csrf() ?>' })
    }).then(r => r.json()).then(data => {
        const el = document.getElementById('lineModalResult');
        if (data.success) { el.innerHTML = '<div class="alert alert-success">✅ 送信しました！</div>'; setTimeout(closeLineModal, 1500); }
        else { el.innerHTML = '<div class="alert alert-danger">❌ ' + (data.error||'送信失敗') + '</div>'; }
    });
}
document.getElementById('lineModal').addEventListener('click', function(e) { if (e.target===this) closeLineModal(); });
</script>

<?php include __DIR__ . '/_footer.php'; ?>
