<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$db      = db();

// ── Ajax：顧客名検索（ダッシュボード新規予約モーダル用） ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $q = '%' . ($_GET['q'] ?? '') . '%';
    $rows = $db->prepare('SELECT id, name FROM customers WHERE name LIKE ? ORDER BY name LIMIT 10');
    $rows->execute([$q]);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

$msg     = '';
$msgType = 'success';

function sendCouponQrLine(string $lineUserId, string $name, string $code, string $description, int $discount, ?string $expiredAt): void
{
    try {
        require_once dirname(__DIR__) . '/lib/qr.php';
        require_once dirname(__DIR__) . '/lib/line.php';
        require_once dirname(__DIR__) . '/config/config.php';

        // QRコードのURL（照合ページ）
        $token   = substr(hash_hmac('sha256', $code, env('CRON_SECRET', 'irodori_cron_2024')), 0, 16);
        $scanUrl = 'https://haptic.irodori.tokyo/coupon/check.php?code=' . $code . '&t=' . $token;

        // QR画像ファイルを生成・保存
        $qrUrl = generateQrCodeFile($scanUrl, 'coupon_' . $code);

        // 有効期限テキスト
        $expText = $expiredAt ? date('Y年m月d日', strtotime($expiredAt)) . 'まで' : '無期限';
        $nameStr = $name ? $name . '様' : 'お客様';

        // Flexメッセージ（QR付きクーポン）
        $flex = [
            'type'    => 'flex',
            'altText' => "🎫 クーポンをお送りします（{$code}）",
            'contents' => [
                'type'   => 'bubble',
                'styles' => ['header' => ['backgroundColor' => '#6B9E8A']],
                'header' => [
                    'type'     => 'box',
                    'layout'   => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => '🎫 クーポン', 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'xl'],
                        ['type' => 'text', 'text' => $description, 'color' => '#ffffff', 'size' => 'sm', 'margin' => 'xs'],
                    ],
                ],
                'body' => [
                    'type'     => 'box',
                    'layout'   => 'vertical',
                    'contents' => [
                        ['type' => 'text', 'text' => $nameStr, 'weight' => 'bold', 'size' => 'lg'],
                        ['type' => 'separator', 'margin' => 'md'],
                        [
                            'type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
                            'contents' => [
                                ['type' => 'text', 'text' => '💰 割引額', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                                ['type' => 'text', 'text' => '¥' . number_format($discount), 'size' => 'sm', 'weight' => 'bold', 'flex' => 3],
                            ],
                        ],
                        [
                            'type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
                            'contents' => [
                                ['type' => 'text', 'text' => '📅 有効期限', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                                ['type' => 'text', 'text' => $expText, 'size' => 'sm', 'weight' => 'bold', 'flex' => 3],
                            ],
                        ],
                        ['type' => 'separator', 'margin' => 'md'],
                        [
                            'type'        => 'image',
                            'url'         => $qrUrl,
                            'size'        => 'md',
                            'aspectRatio' => '1:1',
                            'aspectMode'  => 'fit',
                            'margin'      => 'md',
                            'align'       => 'center',
                        ],
                        [
                            'type'  => 'text',
                            'text'  => 'ご来店時にこのQRコードをスタッフにご提示ください',
                            'size'  => 'xs',
                            'color' => '#888888',
                            'wrap'  => true,
                            'align' => 'center',
                            'margin' => 'sm',
                        ],
                        [
                            'type'   => 'text',
                            'text'   => $code,
                            'size'   => 'lg',
                            'weight' => 'bold',
                            'align'  => 'center',
                            'margin' => 'md',
                            'color'  => '#6B9E8A',
                        ],
                    ],
                ],
            ],
        ];

        linePush($lineUserId, [$flex]);

    } catch (Throwable $e) {
        error_log("QR coupon send error: " . $e->getMessage());
    }
}

function getZodiacSign(?string $birthdate): string
{
    if (!$birthdate) return '未登録';
    $m = (int)date('m', strtotime($birthdate));
    $d = (int)date('d', strtotime($birthdate));
    $z = [
        [1,20,'山羊座♑'],[2,19,'水瓶座♒'],[3,21,'魚座♓'],[4,20,'牡羊座♈'],
        [5,21,'牡牛座♉'],[6,21,'双子座♊'],[7,23,'蟹座♋'],[8,23,'獅子座♌'],
        [9,23,'乙女座♍'],[10,23,'天秤座♎'],[11,23,'蠍座♏'],[12,22,'射手座♐'],
    ];
    foreach ($z as [$zm, $zd, $name]) {
        if ($m == $zm && $d < $zd) return $name;
    }
    // 月の後半→次の星座
    $next = [
        1=>'水瓶座♒',2=>'魚座♓',3=>'牡羊座♈',4=>'牡牛座♉',
        5=>'双子座♊',6=>'蟹座♋',7=>'獅子座♌',8=>'乙女座♍',
        9=>'天秤座♎',10=>'蠍座♏',11=>'射手座♐',12=>'山羊座♑',
    ];
    return $next[$m] ?? '不明';
}

// ============================================================
// お客様詳細ページ
// ============================================================
$customerId = (int)($_GET['id'] ?? 0);
if ($customerId) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();
        $action = $_POST['action'] ?? '';

        if ($action === 'update_customer') {
            $referrerId = null;
            if (!empty($_POST['referrer_name'])) {
                $s = $db->prepare('SELECT id FROM customers WHERE name = ? AND id != ? LIMIT 1');
                $s->execute([trim($_POST['referrer_name']), $customerId]);
                $ref = $s->fetch();
                $referrerId = $ref['id'] ?? null;
            }
            // 紹介者が新たに設定された場合、紹介クーポンを両者に発行
            $prevStmt = $db->prepare('SELECT referrer_id FROM customers WHERE id=?');
            $prevStmt->execute([$customerId]);
            $prevReferrerId = $prevStmt->fetchColumn();

            $db->prepare('UPDATE customers SET name=?, furigana=?, gender=?, phone=?, address=?, birthdate=?, referrer_id=?, updated_by=?, updated_at=NOW() WHERE id=?')
               ->execute([$_POST['name'], $_POST['furigana'] ?: null, $_POST['gender'] ?: null, $_POST['phone'] ?: null, $_POST['address'] ?: null, $_POST['birthdate'] ?: null, $referrerId, currentAdminId(), $customerId]);
            auditLog('update', 'customer', $customerId, "情報更新");

            // 紹介者が新たに設定されたらクーポン発行
            if ($referrerId && $referrerId != $prevReferrerId) {
                // 紹介クーポンテンプレート取得（なければデフォルト値）
                $tmplStmt = $db->prepare("SELECT * FROM coupon_templates WHERE name LIKE '%紹介%' AND is_active=1 LIMIT 1");
                $tmplStmt->execute();
                $refTmpl = $tmplStmt->fetch();
                $refDiscount = $refTmpl ? $refTmpl['discount'] : 500;
                $refDesc     = $refTmpl ? $refTmpl['description'] : '紹介クーポン';
                $refDays     = $refTmpl ? $refTmpl['valid_days'] : 60;
                $refExpired  = date('Y-m-d', strtotime("+{$refDays} days"));

                // 紹介してくれた人（referrerId）にクーポン
                $code1 = strtoupper(substr(md5(uniqid('ref1', true)), 0, 8));
                $db->prepare('INSERT INTO coupons (customer_id, code, description, discount, coupon_type, expired_at, created_by) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$referrerId, $code1, $refDesc, $refDiscount, 'referral', $refExpired, currentAdminId()]);

                // 紹介された人（customerId）にもクーポン
                $code2 = strtoupper(substr(md5(uniqid('ref2', true)), 0, 8));
                $db->prepare('INSERT INTO coupons (customer_id, code, description, discount, coupon_type, expired_at, created_by) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$customerId, $code2, $refDesc, $refDiscount, 'referral', $refExpired, currentAdminId()]);

                // LINE通知（両者・QRコード付き）
                require_once dirname(__DIR__) . '/lib/line.php';
                $custName = $_POST['name'];
                $refStmt  = $db->prepare('SELECT name, line_user_id FROM customers WHERE id=?');
                $refStmt->execute([$referrerId]);
                $referrer = $refStmt->fetch();

                // 紹介してくれた人へ（既存客）QR付き
                if (!empty($referrer['line_user_id'])) {
                    sendCouponQrLine(
                        $referrer['line_user_id'],
                        $referrer['name'],
                        $code1,
                        "{$custName}様ご紹介クーポン",
                        $refDiscount,
                        $refExpired
                    );
                }

                // 紹介された人へ（新規客）QR付き
                $stmtLU = $db->prepare('SELECT line_user_id FROM customers WHERE id=?');
                $stmtLU->execute([$customerId]);
                $custLU = $stmtLU->fetchColumn();
                if ($custLU) {
                    sendCouponQrLine(
                        $custLU,
                        $custName,
                        $code2,
                        'ウェルカムクーポン',
                        $refDiscount,
                        $refExpired
                    );
                }
            }
            header('Location: ' . adminUrl('customers.php') . '?id=' . $customerId . '&msg=updated');
            exit;
        }

        if ($action === 'add_note') {
            if (!empty($_POST['note_content'])) {
                $db->prepare('INSERT INTO customer_notes (customer_id, staff_id, content) VALUES (?,?,?)')
                   ->execute([$customerId, $_SESSION['staff_id'] ?? null, $_POST['note_content']]);
                header('Location: ' . adminUrl('customers.php') . '?id=' . $customerId . '&msg=note_added');
                exit;
            }
        }

        if ($action === 'add_reservation') {
            $startAt = $_POST['date'] . ' ' . $_POST['time'] . ':00';
            $menuId  = (int)$_POST['menu_id'];
            $staffId = $_POST['staff_id'] ? (int)$_POST['staff_id'] : null;
            $m = $db->prepare('SELECT duration_min FROM menus WHERE id=?'); $m->execute([$menuId]); $mr = $m->fetch();
            $endAt = date('Y-m-d H:i:s', strtotime($startAt) + ($mr['duration_min'] ?? 60) * 60);
            $db->prepare('INSERT INTO reservations (customer_id, staff_id, menu_id, start_at, end_at, status, note, created_by) VALUES (?,?,?,?,?,"confirmed",?,?)')
               ->execute([$customerId, $staffId, $menuId, $startAt, $endAt, $_POST['note'] ?? '', currentAdminId()]);
            $newId = (int)$db->lastInsertId();
            auditLog('create', 'reservation', $newId, "管理画面から予約追加");
            header('Location: ' . adminUrl('customers.php') . '?id=' . $customerId . '&msg=reservation_added');
            exit;
        }

        if ($action === 'issue_coupon') {
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));

            // テンプレートから取得
            $tmplId = (int)($_POST['template_id'] ?? 0);
            $discountType = 'amount';
            $discountRate = null;
            if ($tmplId) {
                $tmpl = $db->prepare('SELECT * FROM coupon_templates WHERE id=? AND is_active=1');
                $tmpl->execute([$tmplId]);
                $tmpl = $tmpl->fetch();
                if ($tmpl) {
                    $description  = $tmpl['description'];
                    $discount     = (int)$tmpl['discount'];
                    $discountType = $tmpl['discount_type'] ?? 'amount';
                    $discountRate = $discountType === 'percent' ? (int)$tmpl['discount_rate'] : null;
                    $expiredAt    = date('Y-m-d', strtotime('+' . $tmpl['valid_days'] . ' days'));
                    $couponType   = $tmpl['coupon_type'];
                }
            } else {
                $description  = $_POST['description'] ?: '割引クーポン';
                $discountType = $_POST['discount_type'] === 'percent' ? 'percent' : 'amount';
                $discount     = $discountType === 'amount' ? (int)$_POST['discount'] : 0;
                $discountRate = $discountType === 'percent' ? max(1, min(100, (int)$_POST['discount_rate'])) : null;
                $expiredAt    = $_POST['expired_at'] ?: null;
                $couponType   = 'manual';
            }

            $db->prepare('INSERT INTO coupons (customer_id, code, description, discount, discount_rate, discount_type, coupon_type, expired_at, created_by) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$customerId, $code, $description, $discount, $discountRate, $discountType, $couponType ?? 'manual', $expiredAt, currentAdminId()]);
            $newId = (int)$db->lastInsertId();
            auditLog('create', 'coupon', $newId, "クーポン発行：{$code}");

            // QRコード生成＋LINE送信
            $custStmt = $db->prepare('SELECT line_user_id, name FROM customers WHERE id=?');
            $custStmt->execute([$customerId]);
            $custRow = $custStmt->fetch();
            if ($custRow && $custRow['line_user_id']) {
                sendCouponQrLine($custRow['line_user_id'], $custRow['name'], $code, $description, $discount, $expiredAt);
            }

            header('Location: ' . adminUrl('customers.php') . '?id=' . $customerId . '&msg=coupon_issued&code=' . $code);
            exit;
        }

        if ($action === 'add_sale') {
            $db->prepare('
                INSERT INTO product_sales (customer_id, product_id, quantity, price, sold_at, remind_months, remind_enabled, note, created_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ')->execute([
                $customerId,
                (int)$_POST['product_id'],
                max(1, (int)$_POST['quantity']),
                (int)$_POST['sale_price'],
                $_POST['sold_at'],
                $_POST['remind_months'] ? (int)$_POST['remind_months'] : null,
                isset($_POST['remind_enabled']) ? 1 : 0,
                $_POST['sale_note'] ?: null,
                currentAdminId(),
            ]);
            header('Location: ' . adminUrl('customers.php') . '?id=' . $customerId . '&msg=sale_added#sales');
            exit;
        }

        if ($action === 'delete_sale') {
            $db->prepare('DELETE FROM product_sales WHERE id=? AND customer_id=?')->execute([(int)$_POST['sale_id'], $customerId]);
            header('Location: ' . adminUrl('customers.php') . '?id=' . $customerId . '&msg=sale_deleted#sales');
            exit;
        }

        if ($action === 'toggle_remind') {
            $saleId  = (int)$_POST['sale_id'];
            $enabled = (int)$_POST['remind_enabled'];
            $db->prepare('UPDATE product_sales SET remind_enabled=? WHERE id=? AND customer_id=?')->execute([$enabled, $saleId, $customerId]);
            header('Location: ' . adminUrl('customers.php') . '?id=' . $customerId . '&msg=updated#sales');
            exit;
        }

        if ($action === 'delete_coupon') {
            $cpId = (int)$_POST['coupon_id'];
            $db->prepare('DELETE FROM coupons WHERE id=? AND customer_id=? AND used_at IS NULL')->execute([$cpId, $customerId]);
            auditLog('delete', 'coupon', $cpId, "クーポン削除");
            header('Location: ' . adminUrl('customers.php') . '?id=' . $customerId . '&msg=coupon_deleted');
            exit;
        }
    }

    // リダイレクト後のメッセージ
    $msgMap = [
        'updated'          => 'お客様情報を更新しました',
        'note_added'       => 'メモを追加しました',
        'reservation_added'=> '予約を追加しました（確定済み）',
        'coupon_deleted'   => 'クーポンを削除しました',
        'sale_added'       => '物販履歴を追加しました',
        'sale_deleted'     => '物販履歴を削除しました',
    ];
    $getMsgKey = $_GET['msg'] ?? '';
    if ($getMsgKey === 'coupon_issued') {
        $msg = "クーポンを発行しました（コード：" . h($_GET['code'] ?? '') . "）";
    } elseif (isset($msgMap[$getMsgKey])) {
        $msg = $msgMap[$getMsgKey];
        if ($getMsgKey === 'coupon_deleted') $msgType = 'danger';
    }

    $stmt = $db->prepare('SELECT c.*, ref.name AS referrer_name FROM customers c LEFT JOIN customers ref ON c.referrer_id = ref.id WHERE c.id = ?');
    $stmt->execute([$customerId]); $customer = $stmt->fetch();
    if (!$customer) { header('Location: ' . adminUrl('customers.php')); exit; }

    $stmt = $db->prepare('SELECT r.*, m.name AS menu_name, s.name AS staff_name FROM reservations r LEFT JOIN menus m ON r.menu_id = m.id LEFT JOIN staff s ON r.staff_id = s.id WHERE r.customer_id = ? ORDER BY r.start_at DESC'); // キャンセルも含む
    $stmt->execute([$customerId]); $reservations = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT cp.*, a1.admin_name AS created_by_name FROM coupons cp LEFT JOIN audit_logs a1 ON a1.target_type="coupon" AND a1.target_id=cp.id AND a1.action="create" WHERE cp.customer_id = ? ORDER BY cp.issued_at DESC');
    $stmt->execute([$customerId]); $coupons = $stmt->fetchAll();

    $stmt = $db->prepare('SELECT n.*, s.name AS staff_name FROM customer_notes n LEFT JOIN staff s ON n.staff_id = s.id WHERE n.customer_id = ? ORDER BY n.created_at DESC');
    $stmt->execute([$customerId]); $notes = $stmt->fetchAll();

    $menus    = $db->query('SELECT * FROM menus WHERE is_active=1 ORDER BY display_order')->fetchAll();
    $staffAll = $db->query('SELECT * FROM staff WHERE is_active=1 ORDER BY display_order')->fetchAll();
    $products = $db->query('SELECT * FROM products WHERE is_active=1 ORDER BY category, display_order')->fetchAll();

    // 物販履歴
    $salesStmt = $db->prepare('
        SELECT ps.*, p.name AS product_name, p.maker, p.category,
               cat.label AS category_label
        FROM product_sales ps
        JOIN products p ON ps.product_id = p.id
        LEFT JOIN (
            SELECT "shampoo" AS cat, "シャンプー" AS label UNION
            SELECT "treatment","トリートメント" UNION
            SELECT "outbath","アウトバス" UNION
            SELECT "styling","スタイリング" UNION
            SELECT "other","その他"
        ) cat ON p.category = cat.cat
        WHERE ps.customer_id = ?
        ORDER BY ps.sold_at DESC
    ');
    $salesStmt->execute([$customerId]);
    $sales = $salesStmt->fetchAll();

    // 物販追加処理
    if (($_POST['action'] ?? '') === 'add_sale') {
        // この処理は上のPOST処理ブロックに移動済み
    }
    $totalVisits   = count(array_filter($reservations, fn($r) => $r['status'] === 'completed'));
    $statusLabels  = ['pending'=>'仮予約','confirmed'=>'確定','completed'=>'完了','cancelled'=>'ｷｬﾝｾﾙ'];

    $pageTitle = ($customer['name'] ?? 'お客様詳細');
    include __DIR__ . '/_header.php';
?>

<div class="page-title">
    <a href="<?= adminUrl('customers.php') ?>" style="font-size:0.7em;font-weight:normal;">← お客様一覧</a><br>
    <?= h($customer['name'] ?? '名前未登録') ?>様
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:360px 1fr;gap:20px;">
<div>

<!-- 基本情報カード（表示/編集モード） -->
<div class="card" id="customerInfoCard">
    <div class="card-header">
        基本情報
        <div style="display:flex;gap:8px;">
            <?php if ($customer['line_user_id']): ?>
            <button class="btn btn-sm" style="background:#00B900;color:#fff;" onclick="openLineModal('<?= h($customer['line_user_id']) ?>', '<?= h($customer['name'] ?? '') ?>')">📱 LINE送信</button>
            <?php endif; ?>
            <button class="btn btn-sm btn-secondary view-only" onclick="setMode('customerInfoCard','edit')">✏️ 編集</button>
            <button class="btn btn-sm btn-secondary edit-only" onclick="setMode('customerInfoCard','view')">キャンセル</button>
        </div>
    </div>
    <div class="card-body">
        <!-- 表示モード -->
        <div class="view-only">
            <table style="font-size:0.9em;width:100%;">
                <tr><td style="color:#888;padding:6px 0;width:80px;">お名前</td><td><?= h($customer['name'] ?? '未登録') ?>様</td></tr>
                <tr><td style="color:#888;padding:6px 0;">ふりがな</td><td><?= h($customer['furigana'] ?? '未登録') ?></td></tr>
                <tr><td style="color:#888;padding:6px 0;">性別</td><td><?= ['male'=>'男性','female'=>'女性','other'=>'その他'][$customer['gender'] ?? ''] ?? '未登録' ?></td></tr>
                <tr><td style="color:#888;padding:6px 0;">電話</td><td><?= h($customer['phone'] ?? '未登録') ?></td></tr>
                <tr><td style="color:#888;padding:6px 0;">誕生日</td><td>
                    <?php if ($customer['birthdate']):
                        $age = (int)((strtotime('today') - strtotime($customer['birthdate'])) / 86400 / 365.25);
                        echo h(date('Y年m月d日', strtotime($customer['birthdate']))) . '（' . $age . '歳）';
                    else: echo '未登録'; endif; ?>
                </td></tr>
                <tr><td style="color:#888;padding:6px 0;">星座</td>
                    <td><?= h(getZodiacSign($customer['birthdate'] ?? null)) ?></td>
                </tr>
                <tr><td style="color:#888;padding:6px 0;">住所</td>
                    <td><?php if ($customer['address']): ?><a href="https://maps.google.com/?q=<?= urlencode($customer['address']) ?>" target="_blank"><?= h($customer['address']) ?> 🗺</a><?php else: ?>未登録<?php endif; ?></td>
                </tr>
                <tr><td style="color:#888;padding:6px 0;">紹介者</td><td><?= h($customer['referrer_name'] ?? '未登録') ?></td></tr>
                <tr><td style="color:#888;padding:6px 0;">LINE名</td><td><?= h($customer['line_name'] ?? '未取得') ?></td></tr>
                <tr><td style="color:#888;padding:6px 0;">LINE ID</td><td style="font-size:0.8em;color:#999;word-break:break-all;"><?= h($customer['line_user_id'] ?? '-') ?></td></tr>
            </table>
        </div>
        <!-- 編集モード -->
        <form method="post" class="edit-only">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update_customer">
            <div class="form-group"><label>お名前</label><input type="text" name="name" value="<?= h($customer['name'] ?? '') ?>" required></div>
            <div class="form-group"><label>ふりがな</label><input type="text" name="furigana" value="<?= h($customer['furigana'] ?? '') ?>" placeholder="わたいしゅんすけ"></div>
            <div class="form-group"><label>性別</label>
                <select name="gender">
                    <option value="">未登録</option>
                    <option value="male"   <?= ($customer['gender']??'')==='male'  ?'selected':'' ?>>男性</option>
                    <option value="female" <?= ($customer['gender']??'')==='female'?'selected':'' ?>>女性</option>
                    <option value="other"  <?= ($customer['gender']??'')==='other' ?'selected':'' ?>>その他</option>
                </select>
            </div>
            <div class="form-group"><label>電話番号</label><input type="tel" name="phone" value="<?= h($customer['phone'] ?? '') ?>" placeholder="090-0000-0000"></div>
            <div class="form-group"><label>誕生日</label><input type="date" name="birthdate" value="<?= h($customer['birthdate'] ?? '') ?>"></div>
            <div class="form-group"><label>住所</label><input type="text" name="address" value="<?= h($customer['address'] ?? '') ?>" placeholder="静岡県静岡市..."></div>
            <div class="form-group" style="position:relative;">
                <label>紹介者（お客様名）</label>
                <input type="text"
                       id="referrerInput"
                       name="referrer_name"
                       value="<?= h($customer['referrer_name'] ?? '') ?>"
                       placeholder="名前・ふりがな・電話番号で検索"
                       autocomplete="off"
                       oninput="searchReferrer(this.value)">
                <div id="referrerSuggest"
                     style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:0 0 6px 6px;box-shadow:0 4px 12px rgba(0,0,0,0.1);z-index:1000;max-height:220px;overflow-y:auto;">
                </div>
            </div>
            <div class="form-group"><label>LINE名</label><div class="field-value" style="color:#888;"><?= h($customer['line_name'] ?? '未取得') ?></div></div>
            <button class="btn btn-primary btn-sm" type="submit">保存する</button>
        </form>
    </div>
</div>

<!-- サマリー -->
<div class="card">
    <div class="card-header">サマリー</div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;text-align:center;">
            <div style="background:#f8f9fa;border-radius:6px;padding:12px;"><div style="font-size:1.8em;font-weight:bold;color:#6B9E8A;"><?= $totalVisits ?></div><div style="font-size:0.8em;color:#888;">来店回数</div></div>
            <div style="background:#f8f9fa;border-radius:6px;padding:12px;"><div style="font-size:1.8em;font-weight:bold;color:#6B9E8A;"><?= count(array_filter($coupons, fn($c) => !$c['used_at'])) ?></div><div style="font-size:0.8em;color:#888;">未使用クーポン</div></div>
            <div style="background:#f8f9fa;border-radius:6px;padding:12px;margin-top:10px;"><div style="font-size:1.4em;font-weight:bold;color:#6B9E8A;">¥<?= number_format(array_sum(array_column($sales ?? [], 'price'))) ?></div><div style="font-size:0.8em;color:#888;">物販累計</div></div>
        </div>
        <div style="margin-top:10px;font-size:0.8em;color:#888;">
            登録日：<?= h(date('Y/m/d', strtotime($customer['created_at']))) ?>
            <?php if ($customer['updated_at'] ?? null): ?>· 更新：<?= h(date('Y/m/d', strtotime($customer['updated_at']))) ?><?php endif; ?>
        </div>
    </div>
</div>

<!-- スタッフメモ -->
<div class="card">
    <div class="card-header">スタッフメモ</div>
    <div class="card-body">
        <form method="post" style="margin-bottom:10px;">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add_note">
            <textarea name="note_content" rows="2" placeholder="次回提案・施術メモなど" style="margin-bottom:6px;"></textarea>
            <button class="btn btn-primary btn-sm" type="submit">追加</button>
        </form>
        <?php foreach ($notes as $n): ?>
        <div style="margin-top:8px;padding:10px;background:#f8f9fa;border-radius:6px;font-size:0.85em;">
            <div style="color:#888;margin-bottom:3px;"><?= h(date('Y/m/d H:i', strtotime($n['created_at']))) ?><?= $n['staff_name'] ? ' · ' . h($n['staff_name']) : '' ?></div>
            <div><?= nl2br(h($n['content'])) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($notes)): ?><p style="color:#aaa;font-size:0.85em;">メモがありません</p><?php endif; ?>
    </div>
</div>
</div>

<!-- 右カラム -->
<div>

<!-- 予約履歴 -->
<div class="card">
    <div class="card-header">
        予約履歴
        <button class="btn btn-sm btn-primary" onclick="toggleSection('addReservation')">＋ 新規予約追加</button>
    </div>
    <div id="addReservation" style="display:none;padding:16px;background:#f8f9fa;border-bottom:1px solid #eee;">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add_reservation">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>日付</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="form-group" style="margin:0;"><label>時間</label><input type="time" name="time" value="10:00" required></div>
                <div class="form-group" style="margin:0;"><label>メニュー</label>
                    <select name="menu_id" required><?php foreach ($menus as $m): ?><option value="<?= $m['id'] ?>"><?= h($m['name']) ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group" style="margin:0;"><label>担当</label>
                    <select name="staff_id"><option value="">指名なし</option><?php foreach ($staffAll as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select>
                </div>
            </div>
            <div class="form-group" style="margin-top:10px;"><label>備考</label><input type="text" name="note" placeholder="任意"></div>
            <button class="btn btn-primary btn-sm" type="submit">追加（確定済みで登録）</button>
        </form>
    </div>
    <div style="padding:0;">
        <table>
            <tr><th>日時</th><th>メニュー</th><th>担当</th><th>状態</th><th>操作</th></tr>
            <?php foreach ($reservations as $r): ?>
            <tr style="<?= $r['status']==='cancelled'?'opacity:0.5;background:#fafafa;':''; ?>">
                <td style="white-space:nowrap;"><a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $r['id'] ?>"><?php $dow=['日','月','火','水','木','金','土'][date('w',strtotime($r['start_at']))]; echo h(date('m/d（'.$dow.'） H:i',strtotime($r['start_at']))); ?></a></td>
                <td><?= h($r['menu_name'] ?? '-') ?></td>
                <td><?= h($r['staff_name'] ?? '未定') ?></td>
                <td><span class="badge badge-<?= h($r['status']) ?>"><?= $statusLabels[$r['status']] ?? $r['status'] ?></span></td>
                <td><a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">詳細</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($reservations)): ?><tr><td colspan="5" style="text-align:center;padding:20px;color:#888;">予約履歴がありません</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<!-- クーポン -->
<div class="card">
    <div class="card-header">
        クーポン
        <button class="btn btn-sm btn-primary" onclick="toggleSection('addCoupon')">＋ 新規発行</button>
    </div>
    <div id="addCoupon" style="display:none;padding:16px;background:#f8f9fa;border-bottom:1px solid #eee;">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="issue_coupon">
            <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
                <div class="form-group" style="margin:0;">
                    <label>クーポンテンプレートを選択</label>
                    <select name="template_id" required>
                        <option value="">-- 選択してください --</option>
                        <?php
                        $tmplList = $db->query('SELECT * FROM coupon_templates WHERE is_active=1 ORDER BY display_order')->fetchAll();
                        foreach ($tmplList as $t):
                            $dLabel = ($t['discount_type']??'amount')==='percent'
                                ? ($t['discount_rate'].'% OFF')
                                : ('¥'.number_format($t['discount']));
                        ?>
                        <option value="<?= $t['id'] ?>"><?= h($t['name']) ?>（<?= $dLabel ?>・<?= $t['valid_days'] ?>日間）</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary" type="submit" style="margin-bottom:1px;">発行</button>
            </div>
            <div style="margin-top:8px;font-size:0.85em;color:#888;">
                ※ 有効期限・割引はテンプレートの設定が適用されます。
                <a href="<?= adminUrl('coupon_templates.php') ?>" target="_blank">テンプレート管理</a>
            </div>
        </form>
    </div>
    <div style="padding:0;">
        <table>
            <tr><th>コード</th><th>内容</th><th>割引</th><th>有効期限</th><th>発行者</th><th>状態</th><th>操作</th></tr>
            <?php foreach ($coupons as $cp):
                $cpDiscLabel = ($cp['discount_type']??'amount')==='percent'
                    ? ($cp['discount_rate'].'% OFF')
                    : ('¥'.number_format($cp['discount']));
            ?>
            <tr>
                <td><code style="background:#f0f0f0;padding:2px 8px;border-radius:3px;letter-spacing:1px;"><?= h($cp['code']) ?></code></td>
                <td><?= h($cp['description']) ?></td>
                <td><?= $cpDiscLabel ?></td>
                <td><?= $cp['expired_at'] ? h(date('Y/m/d', strtotime($cp['expired_at']))) : '無期限' ?></td>
                <td style="font-size:0.85em;color:#888;"><?= h($cp['created_by_name'] ?? '-') ?></td>
                <td>
                    <?php if ($cp['used_at']): ?><span class="badge" style="background:#e2e3e5;color:#383d41;">使用済 <?= h(date('m/d', strtotime($cp['used_at']))) ?></span>
                    <?php elseif ($cp['expired_at'] && strtotime($cp['expired_at']) < time()): ?><span class="badge" style="background:#f8d7da;color:#721c24;">期限切れ</span>
                    <?php else: ?><span class="badge" style="background:#d4edda;color:#155724;">未使用</span><?php endif; ?>
                </td>
                <td><?php if (!$cp['used_at']): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="delete_coupon">
                        <input type="hidden" name="coupon_id" value="<?= $cp['id'] ?>">
                        <button class="btn btn-danger btn-sm" onclick="return confirm('削除しますか？')">削除</button>
                    </form>
                <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($coupons)): ?><tr><td colspan="7" style="text-align:center;padding:20px;color:#888;">クーポンがありません</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<!-- 物販履歴 -->
<div class="card" id="sales">
    <div class="card-header">
        物販履歴
        <button class="btn btn-sm btn-primary" onclick="toggleSection('addSale')">＋ 追加</button>
    </div>
    <div id="addSale" style="display:none;padding:16px;background:#f8f9fa;border-bottom:1px solid #eee;">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add_sale">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;align-items:end;">
                <div class="form-group" style="margin:0;">
                    <label>商品</label>
                    <select name="product_id" required onchange="setSalePrice(this)">
                        <option value="">-- 選択 --</option>
                        <?php
                        $catLabels = ['shampoo'=>'シャンプー','treatment'=>'トリートメント','outbath'=>'アウトバス','styling'=>'スタイリング','other'=>'その他'];
                        $curCat = '';
                        foreach ($products as $pr):
                            if ($pr['category'] !== $curCat) {
                                if ($curCat) echo '</optgroup>';
                                $curCat = $pr['category'];
                                echo '<optgroup label="' . h($catLabels[$curCat] ?? $curCat) . '">';
                            }
                        ?>
                        <option value="<?= $pr['id'] ?>" data-price="<?= $pr['price'] ?>"><?= h($pr['name']) ?>（¥<?= number_format($pr['price']) ?>）</option>
                        <?php endforeach; if ($curCat) echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0;"><label>数量</label><input type="number" name="quantity" value="1" min="1"></div>
                <div class="form-group" style="margin:0;"><label>販売価格（円）</label><input type="number" name="sale_price" id="custSalePrice" value="0" min="0"></div>
                <div class="form-group" style="margin:0;"><label>販売日</label><input type="date" name="sold_at" value="<?= date('Y-m-d') ?>"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:10px;align-items:end;margin-top:10px;">
                <div class="form-group" style="margin:0;"><label>備考</label><input type="text" name="sale_note" placeholder="任意"></div>
                <div class="form-group" style="margin:0;"><label>購入後リマインド</label>
                    <select name="remind_months">
                        <option value="">なし</option>
                        <option value="1">1ヶ月後</option>
                        <option value="2">2ヶ月後</option>
                        <option value="3">3ヶ月後</option>
                        <option value="6">6ヶ月後</option>
                    </select>
                </div>
                <div style="padding-top:20px;"><label style="font-weight:normal;display:flex;align-items:center;gap:5px;"><input type="checkbox" name="remind_enabled" checked> リマインドON</label></div>
                <button class="btn btn-primary" type="submit">追加</button>
            </div>
        </form>
    </div>
    <div style="padding:0;">
        <table>
            <tr><th>販売日</th><th>商品</th><th>数量</th><th>価格</th><th>リマインド</th><th>操作</th></tr>
            <?php foreach ($sales as $s): ?>
            <tr>
                <td><?= h(date('Y/m/d', strtotime($s['sold_at']))) ?></td>
                <td><?= h($s['product_name']) ?><?php if ($s['maker']): ?> <span style="color:#888;font-size:0.82em;">/<?= h($s['maker']) ?></span><?php endif; ?></td>
                <td><?= h($s['quantity']) ?>個</td>
                <td>¥<?= number_format($s['price']) ?></td>
                <td>
                    <?php if ($s['remind_months']): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="toggle_remind">
                        <input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="remind_enabled" value="<?= $s['remind_enabled'] ? 0 : 1 ?>">
                        <button class="btn btn-sm" style="background:<?= $s['remind_enabled'] ? '#d4edda' : '#f8f9fa' ?>;color:<?= $s['remind_enabled'] ? '#155724' : '#888' ?>;border:1px solid #ddd;" type="submit">
                            <?= $s['remind_months'] ?>ヶ月後 <?= $s['remind_enabled'] ? '🔔ON' : '🔕OFF' ?>
                        </button>
                    </form>
                    <?php else: ?><span style="color:#ccc;font-size:0.85em;">-</span><?php endif; ?>
                </td>
                <td>
                    <form method="post" style="display:inline;" onsubmit="return confirm('削除しますか？')">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="delete_sale">
                        <input type="hidden" name="sale_id" value="<?= $s['id'] ?>">
                        <button class="btn btn-danger btn-sm">削除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($sales)): ?><tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">購入履歴がありません</td></tr><?php endif; ?>
        </table>
    </div>
</div>

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
            <div class="form-group">
                <label>メッセージ</label>
                <textarea id="lineModalText" rows="5" style="width:100%;"></textarea>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="btn btn-secondary" onclick="closeLineModal()">キャンセル</button>
                <button class="btn" style="background:#00B900;color:#fff;" onclick="sendLineMessage()">📱 送信する</button>
            </div>
            <div id="lineModalResult" style="margin-top:10px;"></div>
        </div>
    </div>
</div>

<script>
let currentLineUserId = '';

function openLineModal(lineUserId, name) {
    currentLineUserId = lineUserId;
    document.getElementById('lineModalName').textContent = name;
    document.getElementById('lineModalResult').innerHTML = '';
    document.getElementById('lineModalText').value = name + '様\n\nいつもご来店ありがとうございます✨\n';
    const modal = document.getElementById('lineModal');
    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
}
function closeLineModal() {
    document.getElementById('lineModal').style.display = 'none';
}
function sendLineMessage() {
    const text = document.getElementById('lineModalText').value.trim();
    if (!text) { alert('メッセージを入力してください'); return; }
    fetch('<?= adminUrl('send_line.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ line_user_id: currentLineUserId, message: text, csrf_token: '<?= csrf() ?>' })
    })
    .then(r => r.json())
    .then(data => {
        const el = document.getElementById('lineModalResult');
        if (data.success) {
            el.innerHTML = '<div class="alert alert-success">✅ 送信しました！</div>';
            setTimeout(closeLineModal, 1500);
        } else {
            el.innerHTML = '<div class="alert alert-danger">❌ ' + (data.error || '送信失敗') + '</div>';
        }
    });
}
document.getElementById('lineModal').addEventListener('click', function(e) { if (e.target === this) closeLineModal(); });

function setSalePrice(sel) {
    const opt = sel.options[sel.selectedIndex];
    const el = document.getElementById('custSalePrice');
    if (el) el.value = opt.getAttribute('data-price') || 0;
}
function setPrice(sel) {
    const opt = sel.options[sel.selectedIndex];
    const price = opt.dataset.price || 0;
    const priceEl = document.getElementById('salePrice');
    if (priceEl) priceEl.value = price;
}
function toggleSection(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function setMode(cardId, mode) {
    const card = document.getElementById(cardId);
    card.classList.remove('view-mode', 'edit-mode');
    card.classList.add(mode + '-mode');
}
// ---- 紹介者サジェスト ----
let _referrerTimer = null;
const _currentCustomerId = <?= (int)$customerId ?>;

function searchReferrer(val) {
    const box = document.getElementById('referrerSuggest');
    clearTimeout(_referrerTimer);
    if (val.trim().length < 1) { box.style.display = 'none'; return; }
    _referrerTimer = setTimeout(() => {
        fetch(`<?= adminUrl('api/suggest_referrer.php') ?>?q=${encodeURIComponent(val)}&exclude=${_currentCustomerId}`)
            .then(r => r.json())
            .then(list => {
                if (!list.length) { box.style.display = 'none'; return; }
                box.innerHTML = list.map(c => {
                    const sub = [c.furigana, c.phone].filter(Boolean).join(' / ');
                    return `<div class="referrer-item"
                                 style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:0.9em;"
                                 onmousedown="selectReferrer('${c.name.replace(/'/g,"\\'")}')">
                        <span style="font-weight:bold;">${escHtml(c.name)}</span>
                        ${sub ? `<span style="color:#999;margin-left:8px;font-size:0.85em;">${escHtml(sub)}</span>` : ''}
                    </div>`;
                }).join('');
                box.style.display = 'block';
            });
    }, 200);
}

function selectReferrer(name) {
    document.getElementById('referrerInput').value = name;
    document.getElementById('referrerSuggest').style.display = 'none';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#referrerInput') && !e.target.closest('#referrerSuggest')) {
        const box = document.getElementById('referrerSuggest');
        if (box) box.style.display = 'none';
    }
});
document.addEventListener('mouseover', function(e) {
    if (e.target.closest('.referrer-item')) e.target.closest('.referrer-item').style.background = '#f0f7f4';
});
document.addEventListener('mouseout', function(e) {
    if (e.target.closest('.referrer-item')) e.target.closest('.referrer-item').style.background = '#fff';
});

// 初期状態は表示モード
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.card').forEach(c => c.classList.add('view-mode'));
});
</script>

<?php
    include __DIR__ . '/_footer.php';
    exit;
}

// ============================================================
// お客様一覧
// ============================================================
$search = $_GET['q'] ?? '';
$params = []; $where = ['1=1'];
if ($search) { $where[] = '(c.name LIKE ? OR c.furigana LIKE ? OR c.phone LIKE ? OR c.line_name LIKE ?)'; $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; $params[] = "%{$search}%"; }

$stmt = $db->prepare('
    SELECT c.*,
           COUNT(DISTINCT CASE WHEN r.status="completed" THEN r.id END) AS reservation_count,
           MAX(CASE WHEN r.status="completed" THEN r.start_at END) AS last_visit,
           (SELECT COUNT(*) FROM coupons cp2 WHERE cp2.customer_id=c.id AND cp2.used_at IS NULL AND (cp2.expired_at IS NULL OR cp2.expired_at > NOW())) AS active_coupons
    FROM customers c
    LEFT JOIN reservations r ON c.id = r.customer_id
    WHERE ' . implode(' AND ', $where) . '
    GROUP BY c.id ORDER BY c.created_at DESC LIMIT 100
');
$stmt->execute($params);
$customers = $stmt->fetchAll();

$pageTitle = 'お客様一覧';
include __DIR__ . '/_header.php';
?>

<div class="page-title">お客様一覧（<?= count($customers) ?>名）</div>
<div class="card"><div class="card-body" style="padding:14px 20px;">
    <form method="get" style="display:flex;gap:12px;">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="名前・電話番号で検索" style="width:260px;">
        <button class="btn btn-secondary btn-sm" type="submit">検索</button>
        <?php if ($search): ?><a href="<?= adminUrl('customers.php') ?>" class="btn btn-sm" style="background:#eee;color:#333;">クリア</a><?php endif; ?>
    </form>
</div></div>

<div class="card"><div class="card-body" style="padding:0;">
    <table>
        <tr><th>名前</th><th>ふりがな</th><th>LINE名</th><th>電話</th><th>来店回数</th><th>最終来店</th><th>クーポン</th><th>登録日</th><th>LINE</th></tr>
        <?php foreach ($customers as $c): ?>
        <tr>
            <td><a href="<?= adminUrl('customers.php') ?>?id=<?= $c['id'] ?>"><?= h($c['name'] ?? '名前未登録') ?>様</a></td>
            <td style="color:#888;font-size:0.85em;"><?= h($c['furigana'] ?? '-') ?></td>
            <td style="color:#888;font-size:0.85em;"><?= h($c['line_name'] ?? '-') ?></td>
            <td><?= h($c['phone'] ?? '-') ?></td>
            <td><?= h($c['reservation_count']) ?>回</td>
            <td><?= $c['last_visit'] ? h(date('Y/m/d', strtotime($c['last_visit']))) : '-' ?></td>
            <td><?php if ($c['active_coupons'] > 0): ?><span class="badge" style="background:#d4edda;color:#155724;">🎫 <?= $c['active_coupons'] ?>枚</span><?php else: ?><span style="color:#ccc;font-size:0.85em;">-</span><?php endif; ?></td>
            <td><?= h(date('Y/m/d', strtotime($c['created_at']))) ?></td>
            <td style="text-align:center;">
                <?php if (!empty($c['line_user_id'])): ?>
                <button class="btn btn-sm" style="background:#00B900;color:#fff;padding:4px 10px;" onclick="openLineModal('<?= h($c['line_user_id']) ?>','<?= h($c['name'] ?? '') ?>')">📱</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($customers)): ?><tr><td colspan="9" style="text-align:center;padding:30px;color:#888;">お客様がいません</td></tr><?php endif; ?>
    </table>
</div></div>

<!-- LINE送信モーダル（一覧用） -->
<div id="lineModal2" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;width:500px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="padding:16px 20px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
            <strong>📱 LINEメッセージ送信</strong>
            <button onclick="closeLineModal2()" style="border:none;background:none;font-size:1.4em;cursor:pointer;color:#888;">✕</button>
        </div>
        <div style="padding:20px;">
            <div style="margin-bottom:12px;color:#888;font-size:0.9em;">送信先：<span id="lineModalName2" style="color:#333;font-weight:bold;"></span>様</div>
            <div class="form-group"><label>メッセージ</label><textarea id="lineModalText2" rows="5" style="width:100%;"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="btn btn-secondary" onclick="closeLineModal2()">キャンセル</button>
                <button class="btn" style="background:#00B900;color:#fff;" onclick="sendLineMessage2()">📱 送信する</button>
            </div>
            <div id="lineModalResult2" style="margin-top:10px;"></div>
        </div>
    </div>
</div>
<script>
let currentLineUserId2 = '';
function openLineModal(id, name) {
    currentLineUserId2 = id;
    document.getElementById('lineModalName2').textContent = name;
    document.getElementById('lineModalResult2').innerHTML = '';
    document.getElementById('lineModalText2').value = name + '様\n\nいつもご来店ありがとうございます✨\n';
    const m = document.getElementById('lineModal2');
    m.style.display = 'flex'; m.style.alignItems = 'center'; m.style.justifyContent = 'center';
}
function closeLineModal2() { document.getElementById('lineModal2').style.display = 'none'; }
function sendLineMessage2() {
    const text = document.getElementById('lineModalText2').value.trim();
    if (!text) { alert('メッセージを入力してください'); return; }
    fetch('<?= adminUrl('send_line.php') ?>', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ line_user_id: currentLineUserId2, message: text, csrf_token: '<?= csrf() ?>' })
    }).then(r => r.json()).then(data => {
        const el = document.getElementById('lineModalResult2');
        if (data.success) { el.innerHTML = '<div class="alert alert-success">✅ 送信しました！</div>'; setTimeout(closeLineModal2, 1500); }
        else { el.innerHTML = '<div class="alert alert-danger">❌ ' + (data.error||'送信失敗') + '</div>'; }
    });
}
document.getElementById('lineModal2').addEventListener('click', function(e) { if (e.target === this) closeLineModal2(); });
</script>

<!-- LINE送信モーダル -->
<div id="listLineModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;width:500px;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="padding:16px 20px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
            <strong>📱 LINEメッセージ送信</strong>
            <button onclick="closeLineModal()" style="border:none;background:none;font-size:1.4em;cursor:pointer;color:#888;">✕</button>
        </div>
        <div style="padding:20px;">
            <div style="margin-bottom:12px;color:#888;font-size:0.9em;">送信先：<span id="listLineModalName" style="color:#333;font-weight:bold;"></span>様</div>
            <div class="form-group"><label>メッセージ</label><textarea id="listLineModalText" rows="5" style="width:100%;"></textarea></div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="btn btn-secondary" onclick="closeLineModal()">キャンセル</button>
                <button class="btn" style="background:#00B900;color:#fff;" onclick="sendListLineMessage()">📱 送信する</button>
            </div>
            <div id="listLineModalResult" style="margin-top:10px;"></div>
        </div>
    </div>
</div>
<script>
let _lineUserId = '';
function openLineModal(id, name) {
    _lineUserId = id;
    document.getElementById('listLineModalName').textContent = name;
    document.getElementById('listLineModalResult').innerHTML = '';
    document.getElementById('listLineModalText').value = name + '様\n\nいつもご来店ありがとうございます✨\n';
    const m = document.getElementById('listLineModal');
    m.style.display = 'flex';
    m.style.alignItems = 'center';
    m.style.justifyContent = 'center';
}
function closeLineModal() { document.getElementById('listLineModal').style.display = 'none'; }
function sendListLineMessage() {
    const text = document.getElementById('listLineModalText').value.trim();
    if (!text) { alert('メッセージを入力してください'); return; }
    fetch('<?= adminUrl('send_line.php') ?>', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ line_user_id: _lineUserId, message: text, csrf_token: '<?= csrf() ?>' })
    }).then(r => r.json()).then(data => {
        const el = document.getElementById('listLineModalResult');
        if (data.success) { el.innerHTML = '<div class="alert alert-success">✅ 送信しました！</div>'; setTimeout(closeLineModal, 1500); }
        else { el.innerHTML = '<div class="alert alert-danger">❌ ' + (data.error||'送信失敗') + '</div>'; }
    });
}
document.getElementById('listLineModal').addEventListener('click', function(e) { if (e.target === this) closeLineModal(); });
</script>

<?php include __DIR__ . '/_footer.php'; ?>