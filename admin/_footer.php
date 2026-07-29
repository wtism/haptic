</div><!-- /.main -->

<input type="hidden" id="globalCsrfToken" value="<?= csrf() ?>">

<!-- 共通LINEモーダル -->
<div id="globalLineModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;" class="modal-outer">
    <div class="modal-inner" style="background:#fff;border-radius:10px;width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 10px 40px rgba(0,0,0,0.2);">
        <div style="padding:14px 18px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;">
            <strong>📱 LINEメッセージ送信</strong>
            <button onclick="closeGlobalLineModal()" style="border:none;background:none;font-size:1.4em;cursor:pointer;color:#888;">✕</button>
        </div>
        <div style="padding:18px;">
            <div style="margin-bottom:12px;font-size:0.88em;color:#888;">送信先：<span id="glmName" style="color:#333;font-weight:bold;"></span>様</div>
            <div class="form-group">
                <label>テンプレートから選ぶ</label>
                <select id="glmTemplate" onchange="applyTemplate(this)">
                    <option value="">-- テンプレートを選択 --</option>
                    <?php
                    try {
                        $tmpls = db()->query("SELECT * FROM line_templates WHERE is_active=1 ORDER BY display_order")->fetchAll();
                        foreach ($tmpls as $t) {
                            echo '<option value="' . htmlspecialchars($t['body'], ENT_QUOTES) . '">' . htmlspecialchars($t['name'], ENT_QUOTES) . '</option>';
                        }
                    } catch (Throwable $e) {}
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;justify-content:space-between;">
                    <span>メッセージ本文</span>
                    <button type="button" onclick="toggleGlmAiPanel()"
                        style="display:flex;align-items:center;gap:4px;padding:3px 10px;border:1px solid #a78bfa;border-radius:6px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.8em;cursor:pointer;">
                        ✨ AIで作成
                    </button>
                </label>
                <textarea id="glmText" rows="6" style="width:100%;box-sizing:border-box;"></textarea>
                <div style="text-align:right;font-size:0.78em;color:#aaa;margin-top:2px;"><span id="glmCount">0</span>文字</div>
            </div>

            <!-- AIパネル -->
            <div id="glmAiPanel" style="display:none;background:#f8f7ff;border:1px solid #e0d7ff;border-radius:8px;padding:14px;margin-bottom:14px;">
                <div style="font-size:0.85em;font-weight:600;color:#764ba2;margin-bottom:10px;">✨ AI文章生成</div>
                <div style="margin-bottom:8px;">
                    <input type="text" id="glmAiPurpose" placeholder="用途・シーン（例：誕生日のお祝い、次回来店促進…）"
                        style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;font-size:0.88em;">
                </div>
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
                    <?php foreach (['フレンドリー','丁寧・上品','明るく元気','感謝を込めて'] as $tone): ?>
                    <button type="button" class="glm-tone-btn" data-tone="<?= $tone ?>" onclick="selectGlmTone(this)"
                        style="padding:4px 10px;border:1px solid #ddd;border-radius:14px;background:#fff;font-size:0.8em;cursor:pointer;"><?= $tone ?></button>
                    <?php endforeach; ?>
                </div>
                <div style="margin-bottom:8px;">
                    <input type="text" id="glmAiExtra" placeholder="補足（任意）：クーポン案内を含める、など"
                        style="width:100%;padding:7px 10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;font-size:0.88em;">
                </div>
                <button type="button" onclick="generateGlmAi()" id="glmAiBtn"
                    style="width:100%;padding:8px;border:none;border-radius:6px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-size:0.88em;font-weight:bold;cursor:pointer;">
                    <span id="glmAiBtnText">✨ 生成する</span>
                </button>
                <div id="glmAiError" style="display:none;margin-top:8px;padding:8px 10px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#b91c1c;font-size:0.82em;"></div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="btn btn-secondary" onclick="closeGlobalLineModal()">キャンセル</button>
                <button class="btn" style="background:#00B900;color:#fff;" onclick="sendGlobalLineMessage()">📱 送信する</button>
            </div>
            <div id="glmResult" style="margin-top:10px;"></div>
        </div>
    </div>
</div>

<footer style="position:fixed;bottom:0;left:0;width:100%;background:#2c3e50;color:#aaa;text-align:center;padding:10px;font-size:0.78em;z-index:100;">
    &copy; <?= date('Y') ?> HAPTIC All rights reserved.
</footer>

<script>
let _glmLineUserId = '', _glmCustomerName = '', _glmAiTone = '';

function openLineModal(lineUserId, name, type, code, desc, discount, expiry) {
    _glmLineUserId = lineUserId; _glmCustomerName = name;
    document.getElementById('glmName').textContent = name;
    document.getElementById('glmResult').innerHTML = '';
    document.getElementById('glmTemplate').value = '';
    // AIパネルリセット
    document.getElementById('glmAiPanel').style.display = 'none';
    document.getElementById('glmAiError').style.display = 'none';
    document.getElementById('glmAiExtra').value = '';
    _glmAiTone = '';
    document.querySelectorAll('.glm-tone-btn').forEach(b => {
        b.style.background='#fff'; b.style.borderColor='#ddd'; b.style.color='#333';
    });
    // 用途自動セット
    let purpose = '';
    let text = name + '様\n\nいつもご来店ありがとうございます✨\n';
    if (type === 'birthday') {
        text    = name + '様、お誕生日おめでとうございます🎂\n\nいつもご来店ありがとうございます✨\n';
        purpose = '誕生日のお祝い';
    }
    if (type === 'coupon') {
        text    = name + '様\n\nクーポンの期限が近づいております🎫\n\n【'+(desc||'')+'】\n割引：¥'+((discount||0)).toLocaleString()+'\n期限：'+(expiry||'')+'\n\nお早めにご来店ください😊';
        purpose = 'クーポン期限のご案内';
    }
    document.getElementById('glmText').value = text;
    document.getElementById('glmAiPurpose').value = purpose;
    updateGlmCount();
    const m = document.getElementById('globalLineModal');
    m.style.display = 'flex'; m.style.alignItems = 'center'; m.style.justifyContent = 'center';
}
function closeGlobalLineModal() { document.getElementById('globalLineModal').style.display = 'none'; }
function applyTemplate(sel) {
    if (!sel.value) return;
    document.getElementById('glmText').value = sel.value.replace(/{name}/g, _glmCustomerName);
    updateGlmCount();
}
function updateGlmCount() {
    const el = document.getElementById('glmText');
    if (el) document.getElementById('glmCount').textContent = el.value.length;
}
document.addEventListener('DOMContentLoaded', function() {
    const ta = document.getElementById('glmText');
    if (ta) ta.addEventListener('input', updateGlmCount);
});
function sendGlobalLineMessage() {
    const text = document.getElementById('glmText').value.trim();
    if (!text) { alert('メッセージを入力してください'); return; }
    if (!_glmLineUserId) { alert('送信先のLINEユーザーIDが取得できません'); return; }
    const csrf = document.getElementById('globalCsrfToken')?.value || '';
    fetch('<?= adminUrl('send_line.php') ?>', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ line_user_id: _glmLineUserId, message: text, csrf_token: csrf })
    }).then(r => r.json()).then(data => {
        const el = document.getElementById('glmResult');
        if (data.success) { el.innerHTML = '<div class="alert alert-success">✅ 送信しました！</div>'; setTimeout(closeGlobalLineModal, 1500); }
        else { el.innerHTML = '<div class="alert alert-danger">❌ ' + (data.error||'送信失敗') + '</div>'; }
    });
}
document.getElementById('globalLineModal').addEventListener('click', function(e) { if (e.target===this) closeGlobalLineModal(); });

// ── AI生成 ────────────────────────────────────────────
function toggleGlmAiPanel() {
    const panel = document.getElementById('glmAiPanel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    if (panel.style.display === 'block') document.getElementById('glmAiPurpose').focus();
}
function selectGlmTone(btn) {
    _glmAiTone = btn.dataset.tone;
    document.querySelectorAll('.glm-tone-btn').forEach(b => {
        const active = b === btn;
        b.style.background  = active ? 'linear-gradient(135deg,#667eea,#764ba2)' : '#fff';
        b.style.borderColor = active ? '#667eea' : '#ddd';
        b.style.color       = active ? '#fff' : '#333';
    });
}
async function generateGlmAi() {
    const purpose = document.getElementById('glmAiPurpose').value.trim();
    if (!purpose) { document.getElementById('glmAiPurpose').focus(); return; }
    const btn = document.getElementById('glmAiBtn');
    const txt = document.getElementById('glmAiBtnText');
    txt.textContent = '⏳ 生成中...'; btn.disabled = true;
    document.getElementById('glmAiError').style.display = 'none';
    const tone  = _glmAiTone || 'フレンドリー';
    const extra = document.getElementById('glmAiExtra').value.trim();
    const ref   = document.getElementById('glmText').value.trim();
    try {
        const res = await fetch('<?= adminUrl('api/ai_generate_line.php') ?>', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ purpose, tone, extra, ref })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || '生成失敗');
        document.getElementById('glmText').value = data.text.replace(/\{name\}/g, _glmCustomerName);
        updateGlmCount();
        document.getElementById('glmAiPanel').style.display = 'none';
    } catch(e) {
        document.getElementById('glmAiError').textContent = '生成失敗：' + e.message;
        document.getElementById('glmAiError').style.display = 'block';
    } finally {
        txt.textContent = '✨ 生成する'; btn.disabled = false;
    }
}
</script>
</body>
</html>
