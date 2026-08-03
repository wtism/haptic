<?php
// admin/scenarios.php  - LINEシナリオ配信管理（来店後/購入後◎ヶ月で自動送信）
require_once __DIR__ . '/auth.php';
requireLogin();

$db      = db();
$msg     = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $triggerType   = $_POST['trigger_type'] === 'purchase' ? 'purchase' : 'visit';
        $monthsAfter   = max(1, (int)$_POST['months_after']);
        $name          = trim($_POST['name']);
        $messageText   = trim($_POST['message_text']);
        $displayOrder  = (int)($_POST['display_order'] ?? 0);

        if ($name === '' || $messageText === '') {
            $msg = 'シナリオ名とメッセージ本文は必須です';
            $msgType = 'danger';
        } elseif ($action === 'add') {
            $db->prepare('
                INSERT INTO line_scenarios (name, trigger_type, months_after, message_text, display_order, is_active, created_by)
                VALUES (?,?,?,?,?,1,?)
            ')->execute([$name, $triggerType, $monthsAfter, $messageText, $displayOrder, currentAdminId()]);
            auditLog('create', 'line_scenario', (int)$db->lastInsertId(), "シナリオ作成：{$name}");
            header('Location: ' . adminUrl('scenarios.php') . '?msg=added'); exit;
        } else {
            $id = (int)$_POST['id'];
            $db->prepare('
                UPDATE line_scenarios SET name=?, trigger_type=?, months_after=?, message_text=?, display_order=?, is_active=?
                WHERE id=?
            ')->execute([
                $name, $triggerType, $monthsAfter, $messageText, $displayOrder,
                isset($_POST['is_active']) ? 1 : 0, $id,
            ]);
            auditLog('update', 'line_scenario', $id, "シナリオ更新：{$name}");
            header('Location: ' . adminUrl('scenarios.php') . '?msg=updated'); exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare('DELETE FROM line_scenarios WHERE id=?')->execute([$id]);
        $db->prepare('DELETE FROM line_scenario_logs WHERE scenario_id=?')->execute([$id]);
        header('Location: ' . adminUrl('scenarios.php') . '?msg=deleted'); exit;
    }
}

$msgMap = ['added'=>'シナリオを追加しました','updated'=>'更新しました','deleted'=>'削除しました'];
if (isset($msgMap[$_GET['msg'] ?? ''])) {
    $msg = $msgMap[$_GET['msg']];
    if ($_GET['msg'] === 'deleted') $msgType = 'danger';
}

$scenarios = $db->query('
    SELECT s.*, (SELECT COUNT(*) FROM line_scenario_logs l WHERE l.scenario_id = s.id) AS sent_count
    FROM line_scenarios s ORDER BY s.display_order, s.id
')->fetchAll();

$triggerLabels = ['visit' => '来店後', 'purchase' => '購入後'];
$pageTitle = 'シナリオ配信';
include __DIR__ . '/_header.php';
?>

<div class="page-title">📆 シナリオ配信</div>
<div class="sec-note" style="color:#888;font-size:0.88em;margin:-8px 0 16px;">
    「来店してから◎ヶ月後」「商品購入から◎ヶ月後」に該当するお客様へ、毎日のバッチ処理で自動的にLINEメッセージを送信します。<br>
    一度送信したお客様には、同じ来店・購入に対して再送されません。
</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= h($msg) ?></div><?php endif; ?>

<?php foreach ($scenarios as $s): ?>
<div class="card" id="sc<?= $s['id'] ?>">
    <div class="card-header">
        <div>
            <span style="font-weight:bold;"><?= h($s['name']) ?></span>
            <span style="font-size:0.82em;color:#888;margin-left:8px;">
                <?= $triggerLabels[$s['trigger_type']] ?? $s['trigger_type'] ?> <?= (int)$s['months_after'] ?>ヶ月 ・ 送信済み <?= (int)$s['sent_count'] ?>件
            </span>
            <?= $s['is_active'] ? '' : '<span style="color:#999;font-size:0.8em;margin-left:6px;">（無効）</span>' ?>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-sm btn-secondary view-only" onclick="setMode('sc<?= $s['id'] ?>','edit')">✏️ 編集</button>
            <button class="btn btn-sm btn-secondary edit-only" onclick="setMode('sc<?= $s['id'] ?>','view')">キャンセル</button>
            <form method="post" style="display:inline;" onsubmit="return confirm('削除しますか？送信履歴も削除されます')">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button class="btn btn-danger btn-sm view-only" type="submit">削除</button>
            </form>
        </div>
    </div>
    <div class="card-body view-only">
        <div style="white-space:pre-wrap;font-size:0.9em;color:#444;background:#f8f9fb;border-radius:8px;padding:12px 14px;"><?= h($s['message_text']) ?></div>
    </div>
    <div class="card-body edit-only">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 130px 100px 60px auto;gap:12px;align-items:end;margin-bottom:12px;">
                <div class="form-group" style="margin:0;"><label>シナリオ名</label><input type="text" name="name" value="<?= h($s['name']) ?>" required></div>
                <div class="form-group" style="margin:0;"><label>トリガー</label>
                    <select name="trigger_type">
                        <option value="visit"    <?= $s['trigger_type']==='visit'   ?'selected':'' ?>>来店後</option>
                        <option value="purchase" <?= $s['trigger_type']==='purchase'?'selected':'' ?>>購入後</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;"><label>ヶ月後</label><input type="number" name="months_after" value="<?= (int)$s['months_after'] ?>" min="1" required></div>
                <div class="form-group" style="margin:0;"><label>順</label><input type="number" name="display_order" value="<?= (int)$s['display_order'] ?>" style="width:50px;"></div>
                <label style="font-weight:normal;display:flex;align-items:center;gap:5px;white-space:nowrap;margin-bottom:9px;"><input type="checkbox" name="is_active" <?= $s['is_active']?'checked':'' ?>> 有効</label>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;justify-content:space-between;">
                    <span>メッセージ本文 <span style="color:#888;font-weight:normal;font-size:0.85em;">{name}→お客様名に自動置換</span></span>
                    <button type="button" onclick="toggleScAiPanel('<?= $s['id'] ?>')"
                        style="display:flex;align-items:center;gap:4px;padding:3px 10px;border:1px solid #a78bfa;border-radius:6px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.8em;cursor:pointer;">
                        ✨ AIで作成
                    </button>
                </label>
                <textarea name="message_text" id="scText<?= $s['id'] ?>" rows="4" required><?= h($s['message_text']) ?></textarea>
            </div>
            <?php renderScAiPanel($s['id'], $s['trigger_type'], (int)$s['months_after']); ?>
            <button class="btn btn-primary btn-sm" type="submit">保存</button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- 新規追加 -->
<div class="card">
    <div class="card-header">
        ＋ シナリオを追加
        <button class="btn btn-sm btn-secondary" onclick="toggleSection('addScenario')">開く</button>
    </div>
    <div id="addScenario" style="display:none;">
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:1fr 130px 100px 60px;gap:12px;align-items:end;margin-bottom:12px;">
                <div class="form-group" style="margin:0;"><label>シナリオ名 *</label><input type="text" name="name" required placeholder="例：来店後3ヶ月フォロー"></div>
                <div class="form-group" style="margin:0;"><label>トリガー</label>
                    <select name="trigger_type">
                        <option value="visit">来店後</option>
                        <option value="purchase">購入後</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;"><label>ヶ月後</label><input type="number" name="months_after" value="3" min="1" required></div>
                <div class="form-group" style="margin:0;"><label>順</label><input type="number" name="display_order" value="<?= count($scenarios)+1 ?>" style="width:50px;"></div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;justify-content:space-between;">
                    <span>メッセージ本文 <span style="color:#888;font-weight:normal;font-size:0.85em;">{name}→お客様名に自動置換</span></span>
                    <button type="button" onclick="toggleScAiPanel('new')"
                        style="display:flex;align-items:center;gap:4px;padding:3px 10px;border:1px solid #a78bfa;border-radius:6px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.8em;cursor:pointer;">
                        ✨ AIで作成
                    </button>
                </label>
                <textarea name="message_text" id="scTextnew" rows="4" required placeholder="{name}様&#10;&#10;ご来店から3ヶ月が経ちました…"></textarea>
            </div>
            <?php renderScAiPanel('new', 'visit', 3); ?>
            <button class="btn btn-primary" type="submit">追加</button>
        </form>
    </div>
    </div>
</div>

<?php
// AIパネルを出力するヘルパー（トリガー種別に応じて用途候補を変える）
function renderScAiPanel(string $key, string $triggerType, int $monthsAfter): void
{
    $scenes = $triggerType === 'purchase'
        ? ['商品の補充・再購入のご提案', '使用感のヒアリング', 'リピート特典のご案内']
        : ['久しぶりのご来店を促す', '髪の状態を気遣うメッセージ', '新メニュー・季節メニューのご案内'];
    ?>
    <div id="scAiPanel<?= h($key) ?>" class="sc-ai-panel" style="display:none;background:#f8f7ff;border:1px solid #e0d7ff;border-radius:8px;padding:14px;margin-bottom:14px;">
        <div style="font-size:0.85em;font-weight:600;color:#764ba2;margin-bottom:10px;">✨ AI文章生成</div>
        <div style="font-size:0.78em;color:#888;margin-bottom:5px;">よく使うシーン</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;">
            <?php foreach ($scenes as $scene): ?>
            <button type="button" class="sc-scene-btn" onclick="pickScScene(this)"
                style="padding:5px 11px;border:1px solid #ddd;border-radius:14px;background:#fff;font-size:0.78em;cursor:pointer;"><?= h($scene) ?></button>
            <?php endforeach; ?>
        </div>
        <div style="margin-bottom:8px;">
            <input type="text" class="sc-ai-purpose" placeholder="用途・シーン（上のボタンから選ぶか、自由に入力）"
                style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;font-size:0.88em;">
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
            <?php foreach (['フレンドリー','丁寧・上品','明るく元気','感謝を込めて'] as $tone): ?>
            <button type="button" class="sc-tone-btn" data-tone="<?= $tone ?>" onclick="selectScTone(this)"
                style="padding:4px 10px;border:1px solid #ddd;border-radius:14px;background:#fff;font-size:0.8em;cursor:pointer;"><?= $tone ?></button>
            <?php endforeach; ?>
        </div>
        <div style="margin-bottom:8px;">
            <input type="text" class="sc-ai-extra" value="<?= $triggerType === 'purchase' ? "購入から{$monthsAfter}ヶ月後に届く自動メッセージです" : "来店から{$monthsAfter}ヶ月後に届く自動メッセージです" ?>"
                style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;font-size:0.88em;">
        </div>
        <button type="button" onclick="generateScAi('<?= h($key) ?>')" class="sc-ai-btn"
            style="width:100%;padding:8px;border:none;border-radius:6px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.88em;font-weight:bold;cursor:pointer;">
            <span class="sc-ai-btn-text">✨ 生成する</span>
        </button>
        <div class="sc-ai-error" style="display:none;margin-top:8px;padding:8px 10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#b91c1c;font-size:0.82em;"></div>
    </div>
    <?php
}
?>

<script>
function toggleSection(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function setMode(cardId, mode) {
    const card = document.getElementById(cardId);
    card.classList.remove('view-mode','edit-mode');
    card.classList.add(mode + '-mode');
    card.querySelectorAll('.edit-only').forEach(el => el.style.display = mode === 'edit' ? '' : 'none');
    card.querySelectorAll('.view-only').forEach(el => el.style.display = mode === 'view' ? '' : 'none');
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.card').forEach(c => {
        c.classList.add('view-mode');
        c.querySelectorAll('.edit-only').forEach(el => el.style.display = 'none');
    });
});

// ── AI文章生成（シナリオ用） ──────────────────────────
let _scAiTone = {};

function toggleScAiPanel(key) {
    const panel = document.getElementById('scAiPanel' + key);
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
}
function pickScScene(btn) {
    const panel = btn.closest('.sc-ai-panel');
    panel.querySelector('.sc-ai-purpose').value = btn.textContent;
    panel.querySelectorAll('.sc-scene-btn').forEach(b => {
        const active = b === btn;
        b.style.background = active ? 'linear-gradient(135deg,#667eea,#764ba2)' : '#fff';
        b.style.borderColor = active ? '#667eea' : '#ddd';
        b.style.color       = active ? '#fff' : '#333';
    });
}
function selectScTone(btn) {
    const panel = btn.closest('.sc-ai-panel');
    panel.dataset.tone = btn.dataset.tone;
    panel.querySelectorAll('.sc-tone-btn').forEach(b => {
        const active = b === btn;
        b.style.background  = active ? 'linear-gradient(135deg,#667eea,#764ba2)' : '#fff';
        b.style.borderColor = active ? '#667eea' : '#ddd';
        b.style.color       = active ? '#fff' : '#333';
    });
}
async function generateScAi(key) {
    const panel   = document.getElementById('scAiPanel' + key);
    const purpose = panel.querySelector('.sc-ai-purpose').value.trim();
    if (!purpose) { panel.querySelector('.sc-ai-purpose').focus(); return; }
    const btn     = panel.querySelector('.sc-ai-btn');
    const btnText = panel.querySelector('.sc-ai-btn-text');
    const errEl   = panel.querySelector('.sc-ai-error');
    btnText.textContent = '⏳ 生成中...'; btn.disabled = true;
    errEl.style.display = 'none';
    const tone  = panel.dataset.tone || 'フレンドリー';
    const extra = panel.querySelector('.sc-ai-extra').value.trim();
    const textEl = document.getElementById('scText' + key);
    try {
        const res = await fetch('<?= adminUrl('api/ai_generate_line.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ purpose, tone, extra, ref: textEl.value.trim() })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || '生成失敗');
        textEl.value = data.text;
        panel.style.display = 'none';
    } catch (e) {
        errEl.textContent = '生成失敗：' + e.message;
        errEl.style.display = 'block';
    } finally {
        btnText.textContent = '✨ 生成する'; btn.disabled = false;
    }
}
</script>

<?php include __DIR__ . '/_footer.php'; ?>
