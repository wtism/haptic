<?php
// admin/broadcast.php  - LINE一斉配信
require_once __DIR__ . '/auth.php';
requireLogin();

$db      = db();
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $msgType = $_POST['msg_type'] ?? 'text'; // text / flex / image

    if (in_array($action, ['save_draft', 'schedule', 'send_now'])) {
        $title       = trim($_POST['title'] ?? '');
        $scheduledAt = $_POST['scheduled_at'] ?: null;

        // メッセージJSON組み立て
        $messageJson = buildMessageJson($msgType, $_POST, $_FILES);
        if (!$messageJson) { $msg = 'メッセージ内容を入力してください'; $msgType = 'danger'; goto render; }

        // セグメント
        $segment = [];
        if (!empty($_POST['seg_gender']))    $segment['gender']     = $_POST['seg_gender'];
        if (!empty($_POST['seg_last_days'])) $segment['last_days']  = (int)$_POST['seg_last_days'];
        if (!empty($_POST['seg_age_from']))  $segment['age_from']   = (int)$_POST['seg_age_from'];
        if (!empty($_POST['seg_age_to']))    $segment['age_to']     = (int)$_POST['seg_age_to'];
        if (!empty($_POST['seg_coupon']))    $segment['has_coupon'] = true;

        $status = $action === 'save_draft' ? 'draft' : ($action === 'schedule' ? 'scheduled' : 'sending');

        $db->prepare('INSERT INTO line_broadcasts (title, message_json, segment_json, status, scheduled_at, created_by) VALUES (?,?,?,?,?,?)')
           ->execute([$title ?: '配信 ' . date('Y/m/d H:i'), json_encode($messageJson, JSON_UNESCAPED_UNICODE), $segment ? json_encode($segment, JSON_UNESCAPED_UNICODE) : null, $status, $scheduledAt, currentAdminId()]);
        $broadcastId = (int)$db->lastInsertId();

        if ($action === 'send_now') {
            $sent = executeBroadcast($broadcastId, $messageJson, $segment, $db);
            $db->prepare('UPDATE line_broadcasts SET status="sent", sent_at=NOW(), sent_count=? WHERE id=?')->execute([$sent, $broadcastId]);
            header('Location: ' . adminUrl('broadcast.php') . '?msg=sent&count=' . $sent); exit;
        }
        header('Location: ' . adminUrl('broadcast.php') . '?msg=' . ($action === 'save_draft' ? 'drafted' : 'scheduled')); exit;
    }

    if ($action === 'delete') {
        $db->prepare('DELETE FROM line_broadcasts WHERE id=? AND status="draft"')->execute([(int)$_POST['id']]);
        header('Location: ' . adminUrl('broadcast.php') . '?msg=deleted'); exit;
    }
}

function buildMessageJson(string $type, array $post, array $files): ?array
{
    if ($type === 'text') {
        $text = trim($post['message_text'] ?? '');
        if (!$text) return null;
        return ['type' => 'text', 'text' => $text];
    }

    if ($type === 'image') {
        $imageUrl  = trim($post['image_url'] ?? '');
        $linkUrl   = trim($post['image_link'] ?? '');
        $text      = trim($post['message_text'] ?? '');

        // 画像アップロード
        if (!empty($files['image_file']['size'])) {
            $uploadDir = '/home/mogans/www/haptic.irodori.tokyo/admin/uploads/broadcast/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext      = strtolower(pathinfo($files['image_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) return null;
            $filename = 'bc_' . time() . '.' . $ext;
            if (move_uploaded_file($files['image_file']['tmp_name'], $uploadDir . $filename)) {
                $imageUrl = 'https://haptic.irodori.tokyo/admin/uploads/broadcast/' . $filename;
            }
        }

        if (!$imageUrl) return null;

        $messages = [];
        if ($text) $messages[] = ['type' => 'text', 'text' => $text];
        $messages[] = [
            'type'          => 'image',
            'originalContentUrl' => $imageUrl,
            'previewImageUrl'    => $imageUrl,
        ];
        return ['type' => 'multi', 'messages' => $messages];
    }

    if ($type === 'flex') {
        $title    = trim($post['flex_title']   ?? '');
        $body     = trim($post['flex_body']    ?? '');
        $btnLabel = trim($post['flex_btn_label'] ?? '');
        $btnUrl   = trim($post['flex_btn_url']   ?? '');
        $imageUrl = trim($post['flex_image']     ?? '');
        $color    = $post['flex_color'] ?? '#6B9E8A';

        if (!$title && !$body) return null;

        $contents = [];
        if ($imageUrl) {
            $contents[] = ['type' => 'image', 'url' => $imageUrl, 'size' => 'full', 'aspectRatio' => '20:13', 'aspectMode' => 'cover'];
        }
        if ($title) $contents[] = ['type' => 'text', 'text' => $title, 'weight' => 'bold', 'size' => 'xl', 'margin' => 'md', 'wrap' => true];
        if ($body)  $contents[] = ['type' => 'text', 'text' => $body, 'size' => 'sm', 'color' => '#555555', 'wrap' => true, 'margin' => 'md'];

        $bubble = [
            'type' => 'bubble',
            'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $contents],
        ];

        if ($btnLabel && $btnUrl) {
            $bubble['footer'] = [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [[
                    'type'   => 'button',
                    'style'  => 'primary',
                    'color'  => $color,
                    'action' => ['type' => 'uri', 'label' => $btnLabel, 'uri' => $btnUrl],
                ]],
            ];
        }

        return [
            'type'     => 'flex',
            'altText'  => $title ?: 'お知らせ',
            'contents' => $bubble,
        ];
    }

    return null;
}

function executeBroadcast(int $id, array $msgJson, array $segment, PDO $db): int
{
    require_once dirname(__DIR__) . '/lib/line.php';

    $where = ['c.line_user_id IS NOT NULL']; $params = [];
    if (!empty($segment['gender']))    { $where[] = 'c.gender = ?'; $params[] = $segment['gender']; }
    if (!empty($segment['last_days'])) { $where[] = 'EXISTS (SELECT 1 FROM reservations r WHERE r.customer_id=c.id AND r.status="completed" AND r.start_at >= DATE_SUB(NOW(), INTERVAL ? DAY))'; $params[] = $segment['last_days']; }
    if (!empty($segment['age_from'])) { $where[] = 'TIMESTAMPDIFF(YEAR, c.birthdate, NOW()) >= ?'; $params[] = $segment['age_from']; }
    if (!empty($segment['age_to']))   { $where[] = 'TIMESTAMPDIFF(YEAR, c.birthdate, NOW()) <= ?'; $params[] = $segment['age_to']; }
    if (!empty($segment['has_coupon'])) { $where[] = 'EXISTS (SELECT 1 FROM coupons cp WHERE cp.customer_id=c.id AND cp.used_at IS NULL AND (cp.expired_at IS NULL OR cp.expired_at > NOW()))'; }

    $stmt = $db->prepare('SELECT c.line_user_id FROM customers c WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    $targets = $stmt->fetchAll();

    $sent = 0;
    foreach ($targets as $t) {
        if ($msgJson['type'] === 'multi') {
            linePush($t['line_user_id'], $msgJson['messages']);
        } else {
            linePush($t['line_user_id'], [$msgJson]);
        }
        $sent++;
        usleep(50000);
    }
    return $sent;
}

render:
// 予約配信チェック
$scheduled = $db->query('SELECT * FROM line_broadcasts WHERE status="scheduled" AND scheduled_at <= NOW()')->fetchAll();
foreach ($scheduled as $bc) {
    $msgData = json_decode($bc['message_json'], true);
    $segData = json_decode($bc['segment_json'] ?? '{}', true) ?? [];
    $sent    = executeBroadcast($bc['id'], $msgData, $segData, $db);
    $db->prepare('UPDATE line_broadcasts SET status="sent", sent_at=NOW(), sent_count=? WHERE id=?')->execute([$sent, $bc['id']]);
}

$msgMap = ['sent' => '配信しました！送信数：' . ($_GET['count'] ?? 0) . '件', 'drafted' => '下書き保存しました', 'scheduled' => '予約配信を設定しました', 'deleted' => '削除しました'];
if (isset($msgMap[$_GET['msg'] ?? ''])) { $msg = $msgMap[$_GET['msg']]; if ($_GET['msg']==='deleted') $msgType = 'danger'; }

$broadcasts  = $db->query('SELECT * FROM line_broadcasts ORDER BY created_at DESC LIMIT 30')->fetchAll();
$statusLabels = ['draft'=>'下書き','scheduled'=>'予約済','sending'=>'送信中','sent'=>'送信済'];
$totalLine   = $db->query('SELECT COUNT(*) FROM customers WHERE line_user_id IS NOT NULL')->fetchColumn();

$pageTitle = 'LINE一斉配信';
include __DIR__ . '/_header.php';
?>

<div class="page-title">📢 LINE一斉配信</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
<div>
<div class="card">
    <div class="card-header">新規配信作成</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" id="broadcastForm">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">

            <div class="form-group">
                <label>タイトル（管理用）</label>
                <input type="text" name="title" placeholder="例：5月キャンペーン">
            </div>

            <!-- メッセージタイプ切り替え -->
            <div class="form-group">
                <label>メッセージタイプ</label>
                <div style="display:flex;gap:12px;margin-top:6px;">
                    <label style="font-weight:normal;display:flex;align-items:center;gap:5px;"><input type="radio" name="msg_type" value="text" checked onchange="switchType('text')"> テキスト</label>
                    <label style="font-weight:normal;display:flex;align-items:center;gap:5px;"><input type="radio" name="msg_type" value="image" onchange="switchType('image')"> 画像</label>
                    <label style="font-weight:normal;display:flex;align-items:center;gap:5px;"><input type="radio" name="msg_type" value="flex" onchange="switchType('flex')"> カード型（Flex）</label>
                </div>
            </div>

            <!-- テキスト入力（共通） -->
            <div id="section_text" class="form-group">
                <label>メッセージ本文 <span style="color:#888;font-weight:normal;font-size:0.85em;">絵文字使用可</span></label>
                <textarea name="message_text" id="messageText" rows="6" placeholder="メッセージを入力...&#10;&#10;😊絵文字もそのまま貼り付けられます"></textarea>
                <div style="text-align:right;font-size:0.8em;color:#888;margin-top:4px;"><span id="charCount">0</span>文字</div>
            </div>

            <!-- 画像設定 -->
            <div id="section_image" style="display:none;">
                <div class="form-group">
                    <label>画像ファイル <span style="color:#888;font-weight:normal;font-size:0.85em;">JPG/PNG</span></label>
                    <input type="file" name="image_file" accept="image/jpeg,image/png">
                </div>
                <div class="form-group">
                    <label>または画像URL</label>
                    <input type="text" name="image_url" placeholder="https://...">
                </div>
                <div class="form-group">
                    <label>リンクURL（任意）</label>
                    <input type="url" name="image_link" placeholder="https://...">
                </div>
            </div>

            <!-- Flex設定 -->
            <div id="section_flex" style="display:none;">
                <div class="form-group">
                    <label>画像URL（任意）</label>
                    <input type="text" name="flex_image" placeholder="https://...">
                </div>
                <div class="form-group">
                    <label>タイトル</label>
                    <input type="text" name="flex_title" placeholder="キャンペーンタイトル" id="flexTitle" oninput="updatePreview()">
                </div>
                <div class="form-group">
                    <label>本文</label>
                    <textarea name="flex_body" rows="3" placeholder="詳細テキスト" id="flexBody" oninput="updatePreview()"></textarea>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>ボタンラベル（任意）</label>
                        <input type="text" name="flex_btn_label" placeholder="詳しくはこちら" id="flexBtnLabel" oninput="updatePreview()">
                    </div>
                    <div class="form-group">
                        <label>ボタンURL</label>
                        <input type="url" name="flex_btn_url" placeholder="https://...">
                    </div>
                </div>
                <div class="form-group">
                    <label>ボタン色</label>
                    <input type="color" name="flex_color" value="#6B9E8A" style="width:60px;height:36px;padding:2px;border-radius:4px;cursor:pointer;">
                </div>
                <!-- プレビュー -->
                <div style="background:#f0f0f0;border-radius:10px;padding:12px;margin-top:8px;">
                    <div style="font-size:0.8em;color:#888;margin-bottom:8px;">プレビュー</div>
                    <div id="flexPreview" style="background:#fff;border-radius:8px;padding:14px;box-shadow:0 1px 4px rgba(0,0,0,0.1);">
                        <div id="pvTitle" style="font-weight:bold;font-size:1em;margin-bottom:6px;"></div>
                        <div id="pvBody"  style="font-size:0.85em;color:#555;"></div>
                        <div id="pvBtn"   style="margin-top:10px;display:none;"><span style="background:#6B9E8A;color:#fff;padding:6px 16px;border-radius:5px;font-size:0.85em;"></span></div>
                    </div>
                </div>
            </div>

            <!-- セグメント -->
            <div style="border:1px solid #eee;border-radius:8px;padding:16px;margin:16px 0;">
                <div style="font-weight:bold;font-size:0.9em;margin-bottom:12px;">🎯 配信セグメント（未選択=全員 <?= $totalLine ?>名）</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group" style="margin:0;"><label>性別</label>
                        <select name="seg_gender"><option value="">全員</option><option value="female">女性のみ</option><option value="male">男性のみ</option></select>
                    </div>
                    <div class="form-group" style="margin:0;"><label>最終来店（〇日以内）</label>
                        <select name="seg_last_days"><option value="">指定なし</option><option value="30">30日以内</option><option value="60">60日以内</option><option value="90">90日以内</option><option value="180">180日以内</option></select>
                    </div>
                    <div class="form-group" style="margin:0;"><label>年齢（以上）</label><input type="number" name="seg_age_from" placeholder="例：20" min="0" max="100"></div>
                    <div class="form-group" style="margin:0;"><label>年齢（以下）</label><input type="number" name="seg_age_to" placeholder="例：40" min="0" max="100"></div>
                </div>
                <div style="margin-top:10px;">
                    <label style="font-weight:normal;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="seg_coupon"> 未使用クーポンを持っている方のみ</label>
                </div>
            </div>

            <!-- 予約配信 -->
            <div class="form-group">
                <label>予約配信日時 <span style="color:#888;font-weight:normal;font-size:0.85em;">（空白=即時）</span></label>
                <input type="datetime-local" name="scheduled_at">
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="btn btn-secondary" type="submit" name="action" value="save_draft">💾 下書き保存</button>
                <button class="btn btn-warning"   type="submit" name="action" value="schedule"   onclick="return confirm('予約配信を設定しますか？')">⏰ 予約配信</button>
                <button class="btn" style="background:#00B900;color:#fff;" type="submit" name="action" value="send_now" onclick="return confirm('今すぐ配信しますか？送信後は取り消せません。')">📢 今すぐ配信</button>
            </div>
        </form>
    </div>
</div>
</div>

<!-- 配信履歴 -->
<div>
<div class="card">
    <div class="card-header">配信履歴</div>
    <div class="card-body" style="padding:0;">
        <table>
            <tr><th>タイトル</th><th>タイプ</th><th>状態</th><th>送信数</th><th>日時</th><th>操作</th></tr>
            <?php foreach ($broadcasts as $bc):
                $bcMsg = json_decode($bc['message_json'], true);
                $typeLabel = ['text'=>'テキスト','image'=>'画像','flex'=>'カード','multi'=>'テキスト+画像'][$bcMsg['type'] ?? 'text'] ?? '?';
            ?>
            <tr>
                <td>
                    <?= h($bc['title']) ?>
                    <div style="font-size:0.8em;color:#888;margin-top:2px;">
                        <?php
                        if ($bcMsg['type'] === 'text') echo h(mb_substr($bcMsg['text'] ?? '', 0, 25)) . '...';
                        elseif ($bcMsg['type'] === 'flex') echo h(mb_substr($bcMsg['contents']['body']['contents'][0]['text'] ?? '', 0, 25)) . '...';
                        ?>
                    </div>
                </td>
                <td><span class="badge" style="background:#e8f5f0;color:#6B9E8A;"><?= $typeLabel ?></span></td>
                <td><?php $sl=['draft'=>['#e2e3e5','#383d41'],'scheduled'=>['#fff3cd','#856404'],'sent'=>['#d4edda','#155724'],'sending'=>['#d1ecf1','#0c5460']]; $sc=$sl[$bc['status']]??['#eee','#333']; ?>
                    <span class="badge" style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>;"><?= h($statusLabels[$bc['status']] ?? $bc['status']) ?></span>
                </td>
                <td><?= $bc['status']==='sent' ? $bc['sent_count'].'件' : '-' ?></td>
                <td style="font-size:0.82em;color:#888;">
                    <?php if ($bc['sent_at']): echo date('m/d H:i', strtotime($bc['sent_at']));
                    elseif ($bc['scheduled_at']): echo '予約:'.date('m/d H:i', strtotime($bc['scheduled_at']));
                    else: echo date('m/d H:i', strtotime($bc['created_at'])); endif; ?>
                </td>
                <td><?php if ($bc['status']==='draft'): ?>
                    <form method="post" style="display:inline;" onsubmit="return confirm('削除しますか？')">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $bc['id'] ?>">
                        <button class="btn btn-danger btn-sm">削除</button>
                    </form>
                <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($broadcasts)): ?><tr><td colspan="6" style="text-align:center;padding:20px;color:#888;">配信履歴がありません</td></tr><?php endif; ?>
        </table>
    </div>
</div>
</div>
</div>

<script>
const textarea = document.getElementById('messageText');
const counter  = document.getElementById('charCount');
if (textarea) textarea.addEventListener('input', () => counter.textContent = textarea.value.length);

function switchType(type) {
    document.getElementById('section_text').style.display  = (type === 'text' || type === 'image') ? '' : 'none';
    document.getElementById('section_image').style.display = type === 'image' ? '' : 'none';
    document.getElementById('section_flex').style.display  = type === 'flex'  ? '' : 'none';
    if (type === 'text') document.querySelector('[name=message_text]').placeholder = 'メッセージを入力...\n\n😊絵文字もそのまま貼り付けられます';
    if (type === 'image') document.querySelector('[name=message_text]').placeholder = '画像の上に表示するテキスト（任意）';
}

function updatePreview() {
    const title = document.getElementById('flexTitle').value;
    const body  = document.getElementById('flexBody').value;
    const btn   = document.getElementById('flexBtnLabel').value;
    document.getElementById('pvTitle').textContent = title;
    document.getElementById('pvBody').textContent  = body;
    const pvBtn = document.getElementById('pvBtn');
    if (btn) { pvBtn.style.display = ''; pvBtn.querySelector('span').textContent = btn; }
    else { pvBtn.style.display = 'none'; }
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
