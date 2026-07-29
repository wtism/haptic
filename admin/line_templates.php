<?php
// admin/line_templates.php  - LINEテンプレート管理
require_once __DIR__ . '/auth.php';
requireLogin();

$db  = db();
$msg = '';

$categoryLabels = ['general'=>'一般','reminder'=>'リマインド','coupon'=>'クーポン','birthday'=>'誕生日','other'=>'その他'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $db->prepare('INSERT INTO line_templates (name, body, category, display_order, is_active) VALUES (?,?,?,?,1)')
           ->execute([$_POST['name'], $_POST['body'], $_POST['category'] ?: 'general', (int)$_POST['display_order']]);
        header('Location: ' . adminUrl('line_templates.php') . '?msg=added'); exit;
    }
    if ($action === 'update') {
        $db->prepare('UPDATE line_templates SET name=?, body=?, category=?, display_order=?, is_active=? WHERE id=?')
           ->execute([$_POST['name'], $_POST['body'], $_POST['category'] ?: 'general', (int)$_POST['display_order'], isset($_POST['is_active'])?1:0, (int)$_POST['id']]);
        header('Location: ' . adminUrl('line_templates.php') . '?msg=updated'); exit;
    }
    if ($action === 'delete') {
        $db->prepare('DELETE FROM line_templates WHERE id=?')->execute([(int)$_POST['id']]);
        header('Location: ' . adminUrl('line_templates.php') . '?msg=deleted'); exit;
    }
}

$msgMap = ['added'=>'追加しました','updated'=>'更新しました','deleted'=>'削除しました'];
if (isset($msgMap[$_GET['msg'] ?? ''])) $msg = $msgMap[$_GET['msg']];

$templates = $db->query('SELECT * FROM line_templates ORDER BY display_order')->fetchAll();
$pageTitle  = 'LINEテンプレート';
include __DIR__ . '/_header.php';
?>

<div class="page-title">📱 LINEテンプレート</div>
<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>

<div style="margin-bottom:12px;font-size:0.88em;color:#888;">
    ※ <code>{name}</code> はお客様の名前に自動置換されます
</div>

<?php foreach ($templates as $t): ?>
<div class="card" id="tmpl<?= $t['id'] ?>">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-weight:bold;"><?= h($t['name']) ?></span>
            <span style="background:#e8f5f0;color:#6B9E8A;padding:2px 8px;border-radius:10px;font-size:0.78em;"><?= h($categoryLabels[$t['category']] ?? $t['category']) ?></span>
            <?= $t['is_active'] ? '' : '<span style="color:#999;font-size:0.8em;">（無効）</span>' ?>
        </div>
        <div style="display:flex;gap:6px;">
            <button class="btn btn-sm btn-secondary view-only" onclick="setMode('tmpl<?= $t['id'] ?>','edit')">✏️ 編集</button>
            <button class="btn btn-sm btn-secondary edit-only" onclick="setMode('tmpl<?= $t['id'] ?>','view')">キャンセル</button>
            <form method="post" style="display:inline;" onsubmit="return confirm('削除しますか？')">
                <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button class="btn btn-danger btn-sm view-only">削除</button>
            </form>
        </div>
    </div>
    <!-- 表示モード -->
    <div class="card-body view-only" style="white-space:pre-wrap;font-size:0.9em;color:#555;background:#f8f9fa;border-radius:6px;padding:12px;margin:12px;">
<?= h($t['body']) ?></div>
    <!-- 編集モード -->
    <div class="card-body edit-only">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr 60px auto;gap:12px;align-items:end;margin-bottom:12px;">
                <div class="form-group" style="margin:0;"><label>テンプレート名</label><input type="text" name="name" value="<?= h($t['name']) ?>" required></div>
                <div class="form-group" style="margin:0;"><label>カテゴリ</label>
                    <select name="category"><?php foreach ($categoryLabels as $k=>$v): ?><option value="<?= $k ?>" <?= $t['category']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group" style="margin:0;"><label>順</label><input type="number" name="display_order" value="<?= h($t['display_order']) ?>"></div>
                <div><label style="font-weight:normal;display:flex;align-items:center;gap:4px;margin-bottom:6px;"><input type="checkbox" name="is_active" <?= $t['is_active']?'checked':'' ?>> 有効</label></div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;justify-content:space-between;">
                    <span>本文 <span style="font-weight:normal;color:#888;font-size:0.85em;">{name}=お客様名</span></span>
                    <button type="button" onclick="openAiModal('edit_body_<?= $t['id'] ?>', '<?= h(addslashes($t['category'])) ?>', '<?= h(addslashes($t['name'])) ?>')"
                        style="display:flex;align-items:center;gap:5px;padding:4px 12px;border:1px solid #a78bfa;border-radius:6px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.82em;cursor:pointer;">
                        <span style="font-size:1.1em;">✨</span> AIで作成
                    </button>
                </label>
                <textarea name="body" id="edit_body_<?= $t['id'] ?>" rows="5"><?= h($t['body']) ?></textarea>
            </div>
            <button class="btn btn-primary btn-sm" type="submit">保存</button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- 新規追加 -->
<div class="card">
    <div class="card-header">＋ テンプレートを追加 <button class="btn btn-sm btn-secondary" onclick="toggleSection('addTmpl')">開く</button></div>
    <div id="addTmpl" style="display:none;"><div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf() ?>">
            <input type="hidden" name="action" value="add">
            <div style="display:grid;grid-template-columns:1fr 1fr 60px;gap:12px;margin-bottom:12px;">
                <div class="form-group" style="margin:0;"><label>テンプレート名 *</label><input type="text" name="name" required id="new_tmpl_name"></div>
                <div class="form-group" style="margin:0;"><label>カテゴリ</label>
                    <select name="category" id="new_tmpl_category"><?php foreach ($categoryLabels as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select>
                </div>
                <div class="form-group" style="margin:0;"><label>順</label><input type="number" name="display_order" value="<?= count($templates)+1 ?>"></div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;justify-content:space-between;">
                    <span>本文</span>
                    <button type="button" onclick="openAiModalNew()"
                        style="display:flex;align-items:center;gap:5px;padding:4px 12px;border:1px solid #a78bfa;border-radius:6px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.82em;cursor:pointer;">
                        <span style="font-size:1.1em;">✨</span> AIで作成
                    </button>
                </label>
                <textarea name="body" id="new_tmpl_body" rows="4" placeholder="{name}様&#10;&#10;いつもご来店ありがとうございます✨"></textarea>
            </div>
            <button class="btn btn-primary" type="submit">追加</button>
        </form>
    </div></div>
</div>

<!-- ✨ AI文章生成モーダル -->
<div id="aiModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;" onclick="if(event.target===this)closeAiModal()">
    <div style="background:#fff;border-radius:14px;width:560px;max-width:95vw;max-height:92vh;overflow-y:auto;box-shadow:0 16px 48px rgba(0,0,0,0.25);">
        <!-- ヘッダー -->
        <div style="padding:16px 20px;border-bottom:1px solid #eee;display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#667eea,#764ba2);border-radius:14px 14px 0 0;">
            <div style="display:flex;align-items:center;gap:8px;color:#fff;">
                <span style="font-size:1.4em;">✨</span>
                <strong style="font-size:1em;">AIでLINEメッセージを作成</strong>
            </div>
            <button onclick="closeAiModal()" style="border:none;background:rgba(255,255,255,0.2);color:#fff;font-size:1.2em;cursor:pointer;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;">✕</button>
        </div>
        <div style="padding:20px;">
            <!-- 用途 -->
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-weight:600;">用途・シーン <span style="color:#e74c3c;">*</span></label>
                <input type="text" id="aiPurpose" placeholder="例：誕生日のお祝い、次回来店の促進、新メニューのご案内…"
                    style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
            </div>
            <!-- トーン -->
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-weight:600;">トーン・雰囲気</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;" id="aiToneGroup">
                    <?php foreach (['丁寧・フォーマル','フレンドリー・親しみやすい','明るく元気','落ち着いた・上品','感謝を込めて'] as $tone): ?>
                    <button type="button" class="tone-btn" data-tone="<?= $tone ?>"
                        onclick="selectTone(this)"
                        style="padding:5px 12px;border:1px solid #ddd;border-radius:16px;background:#fff;font-size:0.84em;cursor:pointer;">
                        <?= $tone ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- 補足情報 -->
            <div class="form-group" style="margin-bottom:14px;">
                <label style="font-weight:600;">補足・入れたい要素 <span style="font-weight:normal;color:#aaa;font-size:0.85em;">（任意）</span></label>
                <input type="text" id="aiExtra" placeholder="例：クーポンの案内を含める、予約URLを入れる余白を残す…"
                    style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
            </div>
            <!-- 参考文 -->
            <div class="form-group" style="margin-bottom:18px;">
                <label style="font-weight:600;">参考にしたい文章・既存テキスト <span style="font-weight:normal;color:#aaa;font-size:0.85em;">（任意）</span></label>
                <textarea id="aiRef" rows="3" placeholder="既存テンプレートや参考文があれば貼り付けてください。文体・雰囲気を寄せて生成します。"
                    style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;resize:vertical;"></textarea>
            </div>

            <!-- 生成ボタン -->
            <button onclick="generateAi()" id="aiGenBtn"
                style="width:100%;padding:10px;border:none;border-radius:8px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.95em;font-weight:bold;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
                <span id="aiGenBtnIcon">✨</span>
                <span id="aiGenBtnText">生成する</span>
            </button>

            <!-- 生成結果 -->
            <div id="aiResultWrap" style="display:none;margin-top:18px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <label style="font-weight:600;color:#555;">生成結果</label>
                    <button type="button" onclick="generateAi()" style="font-size:0.8em;color:#764ba2;border:1px solid #a78bfa;background:#fff;padding:3px 10px;border-radius:4px;cursor:pointer;">🔄 再生成</button>
                </div>
                <textarea id="aiResult" rows="6"
                    style="width:100%;padding:10px;border:1px solid #a78bfa;border-radius:8px;box-sizing:border-box;font-size:0.92em;background:#fafaff;resize:vertical;"></textarea>
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                    <button onclick="closeAiModal()" class="btn btn-secondary">キャンセル</button>
                    <button onclick="applyAiResult()"
                        style="padding:8px 20px;border:none;border-radius:6px;background:#6B9E8A;color:#fff;font-weight:bold;cursor:pointer;font-size:0.92em;">
                        ✅ この文章を使う
                    </button>
                </div>
            </div>

            <div id="aiError" style="display:none;margin-top:12px;padding:10px 14px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#b91c1c;font-size:0.88em;"></div>
        </div>
    </div>
</div>

<script>
function toggleSection(id) { const el=document.getElementById(id); el.style.display=el.style.display==='none'?'block':'none'; }
function setMode(cardId, mode) {
    const card = document.getElementById(cardId);
    card.classList.remove('view-mode','edit-mode');
    card.classList.add(mode+'-mode');
    card.querySelectorAll('.edit-only').forEach(el=>el.style.display=mode==='edit'?'':'none');
    card.querySelectorAll('.view-only').forEach(el=>el.style.display=mode==='view'?'':'none');
}
document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('.card').forEach(c=>{
        c.classList.add('view-mode');
        c.querySelectorAll('.edit-only').forEach(el=>el.style.display='none');
    });
});

// ── AI モーダル ────────────────────────────────────────
let _aiTargetId = null;
let _aiSelectedTone = '';

function openAiModal(textareaId, category, name) {
    _aiTargetId = textareaId;
    document.getElementById('aiPurpose').value = name || '';
    document.getElementById('aiExtra').value   = '';
    document.getElementById('aiRef').value     = '';
    document.getElementById('aiResultWrap').style.display = 'none';
    document.getElementById('aiError').style.display = 'none';
    document.getElementById('aiResult').value  = '';
    _aiSelectedTone = '';
    document.querySelectorAll('.tone-btn').forEach(b => {
        b.style.background='#fff'; b.style.borderColor='#ddd'; b.style.color='#333';
    });
    // 既存本文を参考に自動セット
    const existing = document.getElementById(textareaId)?.value?.trim();
    if (existing) document.getElementById('aiRef').value = existing;
    document.getElementById('aiModal').style.display = 'flex';
    setTimeout(() => document.getElementById('aiPurpose').focus(), 100);
}

function openAiModalNew() {
    const name = document.getElementById('new_tmpl_name')?.value || '';
    openAiModal('new_tmpl_body', '', name);
}

function closeAiModal() {
    document.getElementById('aiModal').style.display = 'none';
}

function selectTone(btn) {
    _aiSelectedTone = btn.dataset.tone;
    document.querySelectorAll('.tone-btn').forEach(b => {
        const active = b === btn;
        b.style.background    = active ? 'linear-gradient(135deg,#667eea,#764ba2)' : '#fff';
        b.style.borderColor   = active ? '#667eea' : '#ddd';
        b.style.color         = active ? '#fff' : '#333';
    });
}

async function generateAi() {
    const purpose = document.getElementById('aiPurpose').value.trim();
    if (!purpose) { document.getElementById('aiPurpose').focus(); return; }

    const btn     = document.getElementById('aiGenBtn');
    const btnText = document.getElementById('aiGenBtnText');
    const btnIcon = document.getElementById('aiGenBtnIcon');
    btnText.textContent = '生成中...';
    btnIcon.textContent = '⏳';
    btn.disabled = true;
    document.getElementById('aiError').style.display = 'none';

    const tone  = _aiSelectedTone || 'フレンドリー・親しみやすい';
    const extra = document.getElementById('aiExtra').value.trim();
    const ref   = document.getElementById('aiRef').value.trim();

    try {
        const res = await fetch('<?= adminUrl('api/ai_generate_line.php') ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ purpose, tone, extra, ref })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || '生成に失敗しました');
        document.getElementById('aiResult').value = data.text;
        document.getElementById('aiResultWrap').style.display = 'block';
        document.getElementById('aiResult').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch(e) {
        document.getElementById('aiError').textContent = '生成に失敗しました：' + e.message;
        document.getElementById('aiError').style.display = 'block';
    } finally {
        btnText.textContent = '生成する';
        btnIcon.textContent = '✨';
        btn.disabled = false;
    }
}

function applyAiResult() {
    const text = document.getElementById('aiResult').value.trim();
    if (!text || !_aiTargetId) return;
    const ta = document.getElementById(_aiTargetId);
    if (ta) {
        ta.value = text;
        ta.dispatchEvent(new Event('input'));
    }
    closeAiModal();
}
</script>
