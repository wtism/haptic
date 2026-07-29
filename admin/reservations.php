<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$db     = db();
$msg    = '';
$msgType = 'success';

// リダイレクト後メッセージ
if (($_GET['msg'] ?? '') === 'cancelled') { $msg = '予約をキャンセルしました'; $msgType = 'danger'; }

// POST処理（確定・キャンセル）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'confirm' && $id) {
        $db->prepare('UPDATE reservations SET status = "confirmed" WHERE id = ?')->execute([$id]);
        // LINE Push通知
        $stmt = $db->prepare('
            SELECT r.start_at, r.end_at, r.menu_snapshot, c.line_user_id, c.name AS cname, m.name AS mname, s.name AS sname
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
            // 複数メニューはスナップショットの結合ラベルを優先
            $snap      = json_decode($res['menu_snapshot'] ?? '', true) ?: [];
            $menuLabel = $snap['menu'] ?? ($res['mname'] ?? '');
            $dow   = ['日','月','火','水','木','金','土'][date('w', strtotime($res['start_at']))];
            $dt    = date('m月d日（'.$dow.'） H:i', strtotime($res['start_at']));
            linePush($res['line_user_id'], [textMessage(
                "✅ ご予約が確定しました！\n\n" .
                "📅 {$dt}〜\n" .
                "✂️ {$menuLabel}\n" .
                "👤 担当：{$res['sname']}\n\n" .
                "ご来店をお待ちしております😊"
            )]);
        }
        // リマインドキューに登録（前日18時）
        $resStmt = $db->prepare('SELECT start_at FROM reservations WHERE id=?');
        $resStmt->execute([$id]);
        $resRow  = $resStmt->fetch();
        if ($resRow) {
            $prevDay = date('Y-m-d', strtotime($resRow['start_at'] . ' -1 day')) . ' 18:00:00';
            if (strtotime($prevDay) > time()) {
                // 既存リマインドがなければ登録
                $chk = $db->prepare('SELECT id FROM reminders WHERE reservation_id=? AND sent_flag=0');
                $chk->execute([$id]);
                if (!$chk->fetch()) {
                    $db->prepare('INSERT INTO reminders (reservation_id, send_at) VALUES (?,?)')->execute([$id, $prevDay]);
                }
            }
        }
        $msg = '予約を確定しLINEで通知しました';
    }

    if ($action === 'cancel' && $id) {
        $db->prepare('UPDATE reservations SET status = "cancelled" WHERE id = ?')->execute([$id]);
        $stmt = $db->prepare('SELECT r.start_at, c.line_user_id FROM reservations r LEFT JOIN customers c ON r.customer_id = c.id WHERE r.id = ?');
        $stmt->execute([$id]);
        $res = $stmt->fetch();
        if ($res && $res['line_user_id']) {
            require_once dirname(__DIR__) . '/lib/line.php';
            linePush($res['line_user_id'], [textMessage(
                "ご予約のキャンセルについてご連絡いたします🙏\n\n" .
                "ご希望に添えず大変申し訳ございません。\n" .
                "改めてご予約をご希望の場合はトークよりお知らせください。"
            )]);
        }
        $msg = '予約をキャンセルしLINEで通知しました';
        $msgType = 'danger';
        header('Location: ' . adminUrl('reservations.php') . '?msg=cancelled&msgtype=danger');
        exit;
    }

    if ($action === 'complete' && $id) {
        $db->prepare('UPDATE reservations SET status = "completed" WHERE id = ?')->execute([$id]);
        $msg = '施術完了にしました';
    }
}

// フィルター
$status = $_GET['status'] ?? '';
$date   = $_GET['date']   ?? '';
$period = $_GET['period'] ?? '';
$where  = ['1=1']; // キャンセルも表示（論理削除）
$params = [];

if ($status === 'all') {
    // 全件表示
} elseif ($status) {
    $where[] = 'r.status = ?'; $params[] = $status;
} else {
    $where[] = 'r.status != "cancelled"'; // デフォルトはキャンセル除外
}

if ($period === 'today') {
    $where[] = 'DATE(r.start_at) = ?'; $params[] = date('Y-m-d');
} elseif ($period === 'week') {
    $where[] = 'DATE(r.start_at) BETWEEN ? AND ?';
    $params[] = date('Y-m-d', strtotime('monday this week'));
    $params[] = date('Y-m-d', strtotime('sunday this week'));
} elseif ($period === 'month') {
    $where[] = 'DATE(r.start_at) BETWEEN ? AND ?';
    $params[] = date('Y-m-01');
    $params[] = date('Y-m-t');
} elseif ($date) {
    $where[] = 'DATE(r.start_at) = ?'; $params[] = $date;
} else {
    $where[] = 'DATE(r.start_at) >= ?'; $params[] = date('Y-m-d');
}

$sql = '
    SELECT r.*, c.name AS customer_name, c.line_user_id,
           s.name AS staff_name, m.name AS menu_name
    FROM reservations r
    LEFT JOIN customers c ON r.customer_id = c.id
    LEFT JOIN staff s ON r.staff_id = s.id
    LEFT JOIN menus m ON r.menu_id = m.id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY r.start_at
    LIMIT 100
';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

$pageTitle = '予約一覧';
include __DIR__ . '/_header.php';
?>

<div class="page-title">予約一覧</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div>
<?php endif; ?>

<!-- フィルター -->
<div class="card">
    <div class="card-body" style="padding:14px 20px;">
        <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;">
            <a href="<?= adminUrl('reservations.php') ?>?period=today" class="btn btn-sm <?= ($_GET['period']??'')==='today'?'btn-primary':'btn-secondary' ?>">今日</a>
            <a href="<?= adminUrl('reservations.php') ?>?period=week"  class="btn btn-sm <?= ($_GET['period']??'')==='week' ?'btn-primary':'btn-secondary' ?>">今週</a>
            <a href="<?= adminUrl('reservations.php') ?>?period=month" class="btn btn-sm <?= ($_GET['period']??'')==='month'?'btn-primary':'btn-secondary' ?>">今月</a>
        </div>
        <form method="get" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
            <select name="status" style="width:140px;">
                <option value="">キャンセル以外</option>
                <option value="all">すべて</option>
                <option value="pending"   <?= $status==='pending'   ?'selected':'' ?>>仮予約</option>
                <option value="confirmed" <?= $status==='confirmed' ?'selected':'' ?>>確定</option>
                <option value="completed" <?= $status==='completed' ?'selected':'' ?>>完了</option>
                <option value="cancelled" <?= $status==='cancelled' ?'selected':'' ?>>キャンセルのみ</option>
            </select>
            <input type="date" name="date" value="<?= h($date) ?>" style="width:160px;">
            <button class="btn btn-secondary btn-sm" type="submit">絞り込み</button>
            <a href="<?= adminUrl('reservations.php') ?>" class="btn btn-sm" style="background:#eee;color:#333;">リセット</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding:0;">
        <table>
            <tr><th>日時</th><th>お客様</th><th>メニュー</th><th>担当</th><th>状態</th><th>操作</th></tr>
            <?php foreach ($reservations as $r): ?>
            <tr style="<?= $r['status']==='cancelled'?'opacity:0.5;background:#fafafa;':''; ?>">
                <td>
                    <a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $r['id'] ?>">
                    <?php
                    $dow = ['日','月','火','水','木','金','土'][date('w', strtotime($r['start_at']))];
                    echo h(date('m/d（'.$dow.'） H:i', strtotime($r['start_at'])));
                    ?>〜<?= h(date('H:i', strtotime($r['end_at']))) ?></a>
                </td>
                <td>
                    <a href="<?= adminUrl('customers.php') ?>?id=<?= $r['customer_id'] ?>"><?= h($r['customer_name']) ?>様</a>
                </td>
                <td><?= h($r['menu_name']) ?></td>
                <td><?= h($r['staff_name'] ?? '未定') ?></td>
                <td><span class="badge badge-<?= h($r['status']) ?>"><?= ['pending'=>'仮予約','confirmed'=>'確定','completed'=>'完了','cancelled'=>'ｷｬﾝｾﾙ'][$r['status']] ?? $r['status'] ?></span></td>
                <td style="white-space:nowrap;">
                    <a href="<?= adminUrl('reservation_detail.php') ?>?id=<?= $r['id'] ?>" class="btn btn-sm btn-secondary">詳細</a>
                    <?php if ($r['status'] === 'pending'): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="confirm">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button class="btn btn-primary btn-sm">✅ 確定</button>
                    </form>
                    <?php endif; ?>
                    <?php if ($r['status'] === 'confirmed'): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button class="btn btn-secondary btn-sm">完了</button>
                    </form>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['pending','confirmed'])): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button class="btn btn-danger btn-sm" onclick="return confirm('キャンセルしますか？')">✗</button>
                    </form>
                    <?php endif; ?>
                    <?php if (!empty($r['line_user_id'])): ?>
                    <button class="btn btn-sm" style="background:#00B900;color:#fff;" onclick="openLineModal('<?= h($r['line_user_id']) ?>','<?= h($r['customer_name']) ?>')">LINE</button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($reservations)): ?>
            <tr><td colspan="6" style="text-align:center;padding:30px;color:#888;">該当する予約がありません</td></tr>
            <?php endif; ?>
        </table>
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
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ line_user_id: currentLineUserId, message: text, csrf_token: '<?= csrf() ?>' })
    }).then(r => r.json()).then(data => {
        const el = document.getElementById('lineModalResult');
        if (data.success) { el.innerHTML = '<div class="alert alert-success">✅ 送信しました！</div>'; setTimeout(closeLineModal, 1500); }
        else { el.innerHTML = '<div class="alert alert-danger">❌ ' + (data.error||'送信失敗') + '</div>'; }
    });
}
document.getElementById('lineModal').addEventListener('click', function(e) { if (e.target === this) closeLineModal(); });
</script>

<?php include __DIR__ . '/_footer.php'; ?>
