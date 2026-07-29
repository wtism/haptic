<?php
// admin/shop.php  - 店舗マスタ管理
require_once __DIR__ . '/auth.php';
requireLogin();

$db  = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_shop') {
        $regularHolidays = implode(',', $_POST['regular_holidays'] ?? []);
        // 営業時間バリデーション
        $openTime  = preg_match('/^\d{2}:\d{2}$/', $_POST['open_time']  ?? '') ? $_POST['open_time']  : '10:00';
        $closeTime = preg_match('/^\d{2}:\d{2}$/', $_POST['close_time'] ?? '') ? $_POST['close_time'] : '19:00';
        if ($closeTime <= $openTime) { $openTime = '10:00'; $closeTime = '19:00'; }
        $db->prepare('
            UPDATE shop_settings SET
                shop_name=?, postal_code=?, address1=?, address2=?, address3=?,
                phone=?, web_url=?, email=?, line_id=?, regular_holidays=?, open_time=?, close_time=?, fortune_enabled=?, updated_at=NOW()
            WHERE id=1
        ')->execute([
            $_POST['shop_name'], $_POST['postal_code'] ?: null,
            $_POST['address1'] ?: null, $_POST['address2'] ?: null, $_POST['address3'] ?: null,
            $_POST['phone'] ?: null, $_POST['web_url'] ?: null,
            $_POST['email'] ?: null, $_POST['line_id'] ?: null,
            $regularHolidays ?: '2',
            $openTime . ':00', $closeTime . ':00',
            isset($_POST['fortune_enabled']) ? 1 : 0,
        ]);
        header('Location: ' . adminUrl('shop.php') . '?msg=updated'); exit;
    }

    if ($action === 'add_holiday') {
        $date = $_POST['holiday_date'];
        if ($date) {
            $db->prepare('INSERT IGNORE INTO shop_holidays (holiday_date, reason) VALUES (?,?)')->execute([$date, $_POST['reason'] ?: null]);
        }
        header('Location: ' . adminUrl('shop.php') . '?msg=holiday_added'); exit;
    }

    if ($action === 'delete_holiday') {
        $db->prepare('DELETE FROM shop_holidays WHERE id=?')->execute([(int)$_POST['id']]);
        header('Location: ' . adminUrl('shop.php') . '?msg=holiday_deleted'); exit;
    }
}

$msgMap = ['updated'=>'店舗情報を更新しました','holiday_added'=>'休業日を追加しました','holiday_deleted'=>'休業日を削除しました'];
if (isset($msgMap[$_GET['msg'] ?? ''])) $msg = $msgMap[$_GET['msg']];

$shop     = $db->query('SELECT * FROM shop_settings WHERE id=1')->fetch();
$holidays = $db->query('SELECT * FROM shop_holidays ORDER BY holiday_date')->fetchAll();

$pageTitle = '店舗マスタ';
include __DIR__ . '/_header.php';
?>

<div class="page-title">🏪 店舗マスタ</div>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

<!-- 店舗情報 -->
<div class="card">
    <div class="card-header">店舗情報</div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update_shop">
            <div class="form-group"><label>店舗名 *</label><input type="text" name="shop_name" value="<?= h($shop['shop_name'] ?? '') ?>" required></div>
            <div class="form-group">
                <label>郵便番号</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" name="postal_code" id="postal_code" value="<?= h($shop['postal_code'] ?? '') ?>" placeholder="000-0000" style="width:130px;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="lookupPostal()">住所を検索</button>
                    <span id="postalStatus" style="font-size:0.82em;color:#888;"></span>
                </div>
            </div>
            <div class="form-group"><label>住所1（都道府県）</label><input type="text" name="address1" id="address1" value="<?= h($shop['address1'] ?? '') ?>" placeholder="静岡県"></div>
            <div class="form-group"><label>住所2（市区町村）</label><input type="text" name="address2" id="address2" value="<?= h($shop['address2'] ?? '') ?>" placeholder="静岡市駿河区"></div>
            <div class="form-group"><label>住所3（番地・建物名）</label><input type="text" name="address3" id="address3" value="<?= h($shop['address3'] ?? '') ?>" placeholder="〇〇町1-2-3"></div>
            <div class="form-group"><label>電話番号</label><input type="tel" name="phone" value="<?= h($shop['phone'] ?? '') ?>" placeholder="054-000-0000"></div>
            <div class="form-group"><label>WEBサイト</label><input type="url" name="web_url" value="<?= h($shop['web_url'] ?? '') ?>" placeholder="https://..."></div>
            <div class="form-group"><label>メールアドレス</label><input type="email" name="email" value="<?= h($shop['email'] ?? '') ?>"></div>
            <div class="form-group"><label>店舗LINE ID</label><input type="text" name="line_id" value="<?= h($shop['line_id'] ?? '') ?>" placeholder="@xxxxxxxx"></div>

            <div class="form-group">
                <label>定休日（毎週）</label>
                <?php
                $regularHolidays = array_filter(explode(',', $shop['regular_holidays'] ?? '2'));
                $dowLabels = ['0'=>'日','1'=>'月','2'=>'火','3'=>'水','4'=>'木','5'=>'金','6'=>'土'];
                ?>
                <div style="display:flex;gap:12px;margin-top:6px;flex-wrap:wrap;">
                    <?php foreach ($dowLabels as $k => $v): ?>
                    <label style="font-weight:normal;display:flex;align-items:center;gap:4px;cursor:pointer;">
                        <input type="checkbox" name="regular_holidays[]" value="<?= $k ?>"
                               <?= in_array($k, $regularHolidays) ? 'checked' : '' ?>>
                        <span style="color:<?= $k==='0'?'#e74c3c':($k==='6'?'#3498db':'#333') ?>;font-weight:bold;"><?= $v ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="form-group">
                <label>営業時間（LINE予約カレンダーに連動）</label>
                <div style="display:flex;gap:10px;align-items:center;margin-top:6px;">
                    <input type="time" name="open_time" value="<?= h(substr($shop['open_time'] ?? '10:00:00', 0, 5)) ?>" step="1800" style="width:130px;">
                    <span>〜</span>
                    <input type="time" name="close_time" value="<?= h(substr($shop['close_time'] ?? '19:00:00', 0, 5)) ?>" step="1800" style="width:130px;">
                </div>
                <div style="font-size:0.8em;color:#888;margin-top:4px;">※閉店時間までに施術が終わらない枠は、LINE予約で自動的に受付不可（×）になります</div>
            </div>
            <div class="form-group">
                <label>運勢機能（予約詳細に本日の運勢ポップアップ表示）</label>
                <label style="font-weight:normal;display:flex;align-items:center;gap:8px;margin-top:6px;cursor:pointer;">
                    <input type="checkbox" name="fortune_enabled" <?= !empty($shop['fortune_enabled'])?'checked':'' ?>>
                    <span>✨ 当日予約の詳細を開いたとき、お客様の今日の運勢を表示する</span>
                </label>
            </div>
            <button class="btn btn-primary" type="submit">更新する</button>
        </form>
    </div>
</div>

<!-- 店舗休業日 -->
<div>
<div class="card">
    <div class="card-header">
        店舗休業日
        <button class="btn btn-sm btn-primary" onclick="toggleSection('addHoliday')">＋ 追加</button>
    </div>
    <div id="addHoliday" style="display:none;padding:16px;background:#f8f9fa;border-bottom:1px solid #eee;">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add_holiday">
            <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;">
                <div class="form-group" style="margin:0;"><label>日付</label><input type="date" name="holiday_date" required></div>
                <div class="form-group" style="margin:0;"><label>理由</label><input type="text" name="reason" placeholder="例：年末年始、研修"></div>
                <button class="btn btn-primary btn-sm" type="submit">追加</button>
            </div>
        </form>
    </div>
    <div style="padding:0;max-height:400px;overflow-y:auto;">
        <table>
            <tr><th>日付</th><th>曜日</th><th>理由</th><th></th></tr>
            <?php foreach ($holidays as $h): ?>
            <tr>
                <td><?= h(date('Y/m/d', strtotime($h['holiday_date']))) ?></td>
                <td><?= ['日','月','火','水','木','金','土'][date('w', strtotime($h['holiday_date']))] ?></td>
                <td style="color:#888;"><?= h($h['reason'] ?? '-') ?></td>
                <td>
                    <form method="post" style="display:inline;" onsubmit="return confirm('削除しますか？')">
                        <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="delete_holiday">
                        <input type="hidden" name="id" value="<?= $h['id'] ?>">
                        <button class="btn btn-danger btn-sm">削除</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($holidays)): ?><tr><td colspan="4" style="text-align:center;padding:16px;color:#888;">休業日がありません</td></tr><?php endif; ?>
        </table>
    </div>
</div>

<!-- 店舗情報プレビュー -->
<?php if ($shop && $shop['shop_name']): ?>
<div class="card" style="margin-top:16px;">
    <div class="card-header">プレビュー</div>
    <div class="card-body" style="font-size:0.9em;">
        <div style="font-size:1.1em;font-weight:bold;margin-bottom:8px;"><?= h($shop['shop_name']) ?></div>
        <?php if ($shop['postal_code']): ?><div style="color:#888;">〒<?= h($shop['postal_code']) ?></div><?php endif; ?>
        <?php if ($shop['address1']): ?><div><?= h($shop['address1'].($shop['address2']??'').($shop['address3']??'')) ?></div><?php endif; ?>
        <?php if ($shop['phone']): ?><div>📞 <?= h($shop['phone']) ?></div><?php endif; ?>
        <?php if ($shop['web_url']): ?><div>🌐 <a href="<?= h($shop['web_url']) ?>" target="_blank"><?= h($shop['web_url']) ?></a></div><?php endif; ?>
        <?php if ($shop['email']): ?><div>✉️ <?= h($shop['email']) ?></div><?php endif; ?>
        <?php if ($shop['line_id']): ?><div>💬 <?= h($shop['line_id']) ?></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>
</div>
</div>

<script>
function toggleSection(id) { const el=document.getElementById(id); el.style.display=el.style.display==='none'?'block':'none'; }

function lookupPostal() {
    const code = document.getElementById('postal_code').value.replace(/[^0-9]/g,'');
    if (code.length !== 7) { alert('7桁の郵便番号を入力してください'); return; }
    const status = document.getElementById('postalStatus');
    status.textContent = '検索中...';
    fetch('https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + code)
        .then(r => r.json())
        .then(data => {
            if (data.results && data.results[0]) {
                const r = data.results[0];
                document.getElementById('address1').value = r.address1;
                document.getElementById('address2').value = r.address2 + r.address3;
                document.getElementById('address3').value = '';
                status.textContent = '✅ 住所を反映しました';
                setTimeout(() => status.textContent = '', 3000);
            } else {
                status.textContent = '❌ 該当する住所が見つかりません';
            }
        })
        .catch(() => { status.textContent = '❌ 検索に失敗しました'; });
}

// 郵便番号入力時にEnterで検索
document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('postal_code');
    if (el) el.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); lookupPostal(); }
    });
});
</script>

<?php include __DIR__ . '/_footer.php'; ?>
