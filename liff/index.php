<?php
// ============================================================
// liff/index.php - LINE内Web予約画面（LIFF）
// フロー: メニュー → 日時 → スタイリスト → クーポン → 確認 → 完了
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
$liffId = env('LIFF_ID', '');
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<title>ご予約 | HAPTIC</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --accent:#F0618E;               /* メインピンク */
  --accent-d:#D9497A;             /* 濃いピンク */
  --accent-l:#FDE9F0;             /* 薄ピンク背景 */
  --orange:#F79A4D;               /* サブオレンジ */
  --grad:linear-gradient(135deg,#F0618E 0%,#F79A4D 100%);
  --dark:#B14A6E;                 /* ヘッダー用（未使用時の予備） */
  --bg:#FDF6F4; --card:#fff; --text:#3A2E33;
  --muted:#93767F; --border:#F0D6DC; --danger:#D93A2B; --coupon:#F0813F;
}
html,body{height:100%}
body{
  font-family:-apple-system,BlinkMacSystemFont,'Hiragino Sans','Yu Gothic UI',sans-serif;
  background:var(--bg); color:var(--text); font-size:16px; line-height:1.6;
  -webkit-font-smoothing:antialiased;
  padding-bottom:calc(140px + env(safe-area-inset-bottom,0px)); /* 固定フッターに隠れず最後までスクロールできる余白 */
}

/* ヘッダー */
.hd{background:var(--grad); color:#fff; padding:14px 16px 12px; position:sticky; top:0; z-index:100; box-shadow:0 2px 10px rgba(240,97,142,.3)}
.hd-title{font-weight:700; font-size:1.05em; letter-spacing:.05em; text-align:center}
.steps{display:flex; justify-content:center; align-items:center; margin-top:10px; gap:2px}
.st{display:flex; align-items:center; gap:4px; font-size:10px; color:rgba(255,255,255,.6)}
.st.active{color:#fff}
.st.done{color:rgba(255,255,255,.85)}
.st-n{width:20px; height:20px; border-radius:50%; background:rgba(255,255,255,.3); display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700}
.st.active .st-n{background:#fff; color:var(--accent)}
.st.done .st-n{background:rgba(255,255,255,.75); color:var(--accent-d)}
.st-sep{width:14px; height:1px; background:rgba(255,255,255,.25); margin:0 3px}

/* 本文 */
.main{max-width:560px; margin:0 auto; padding:16px 14px}
.sec-title{font-size:1.05em; font-weight:700; margin:4px 0 12px}
.sec-note{font-size:.82em; color:var(--muted); margin:-6px 0 12px}
.card{background:var(--card); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.07); padding:16px; margin-bottom:12px}

/* メニュー */
.cat{font-size:.82em; font-weight:700; color:var(--accent-d); margin:18px 0 8px; display:flex; align-items:center; gap:6px}
.cat:first-child{margin-top:0}
.menu-item{
  background:var(--card); border:2px solid var(--border); border-radius:14px;
  padding:13px 14px; margin-bottom:8px; cursor:pointer; display:flex; align-items:center; gap:10px;
  transition:border-color .12s, background .12s;
}
.menu-item.sel{border-color:var(--accent); background:var(--accent-l)}
.menu-info{flex:1; min-width:0}
.menu-name{font-weight:700; font-size:1.08em}
.menu-meta{font-size:.93em; color:var(--muted); margin-top:2px}
.menu-radio{width:22px; height:22px; border-radius:50%; border:2px solid #E5C9D2; flex-shrink:0; display:flex; align-items:center; justify-content:center}
.menu-item.sel .menu-radio{border-color:var(--accent); background:var(--accent)}
.menu-item.sel .menu-radio::after{content:''; width:8px; height:8px; border-radius:50%; background:#fff}

/* 日時（HPB風マトリクス） */
.hpb-nav{display:flex; align-items:center; justify-content:space-between; margin-bottom:10px}
.hpb-period{font-size:.88em; font-weight:700}
.nav-btn{
  height:34px; padding:0 13px; border:1px solid var(--border); border-radius:20px;
  background:#fff; font-size:.8em; cursor:pointer; color:var(--muted); font-weight:600;
  -webkit-tap-highlight-color:transparent;
}
.nav-btn:disabled{opacity:.3}
.hpb-wrap{overflow-x:auto; -webkit-overflow-scrolling:touch; margin:0 -14px; padding:0 14px; background:#fff; border-radius:12px}
.hpb-table{border-collapse:collapse; width:100%}
.hpb-table th,.hpb-table td{border:1px solid #eceef2; text-align:center; padding:0}
.t-time{
  width:44px; min-width:44px; font-size:.72em; color:var(--muted); padding:4px 2px;
  background:#f8f9fb; position:sticky; left:0; z-index:2; border-right:2px solid #e5e7eb;
}
.t-head{min-width:40px; padding:5px 2px; background:#f8f9fb}
.t-head.today{background:#FFF9E6}
.t-head.sel-col{background:var(--accent-l)}
.t-d{font-size:.95em; font-weight:800; line-height:1.2}
.t-dow{font-size:.62em; font-weight:600}
.t-dow.sun{color:#E24B4A}.t-dow.sat{color:#185FA5}
.t-cell{width:40px; height:38px; cursor:pointer; font-size:1.05em; color:#C2C9D1; background:#fff}
.t-cell.avail{color:var(--accent); font-weight:800}
.t-cell.past{background:#EDF0F3; color:#C2C9D1; cursor:default}
.t-cell.sel-cell{background:var(--accent); color:#fff; font-size:.7em; font-weight:800}
.sel-info{
  margin-top:12px; padding:11px 12px; background:var(--accent-l); border-radius:10px;
  font-size:.9em; font-weight:700; color:var(--accent-d); text-align:center;
}

/* スタッフ */
.staff-grid{display:grid; grid-template-columns:repeat(2,1fr); gap:10px}
.staff-card{
  background:var(--card); border:2px solid var(--border); border-radius:14px;
  padding:14px 10px; text-align:center; cursor:pointer;
}
.staff-card.sel{border-color:var(--accent); background:var(--accent-l)}
.staff-ph{width:76px; height:76px; border-radius:50%; object-fit:cover; margin:0 auto 8px; display:block; background:#FBE9EF}
.staff-ph-none{width:76px; height:76px; border-radius:50%; background:#FBE9EF; margin:0 auto 8px; display:flex; align-items:center; justify-content:center; font-size:2em}
.staff-name{font-weight:700; font-size:.9em}

/* クーポン */
.coupon-card{
  background:var(--card); border:2px dashed var(--border); border-radius:14px;
  padding:14px; margin-bottom:10px; cursor:pointer; position:relative;
}
.coupon-card.sel{border-color:var(--coupon); border-style:solid; background:#fdf3f2}
.coupon-name{font-weight:700; font-size:.92em}
.coupon-val{color:var(--coupon); font-weight:800; font-size:1.05em; margin-top:2px}
.coupon-exp{font-size:.76em; color:var(--muted); margin-top:2px}

/* マイページ */
.mp-hello{font-size:1.05em; font-weight:700; margin:4px 0 14px}
.rsv-card{background:var(--card); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.07); padding:14px; margin-bottom:10px}
.rsv-top{display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; gap:8px}
.rsv-date{font-weight:800; font-size:.98em}
.badge{font-size:.7em; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; flex-shrink:0}
.badge-pending{background:#FFF3E0; color:#C77700}
.badge-confirmed{background:#E3F4EA; color:#1E7E4D}
.rsv-meta{font-size:.85em; color:var(--muted)}
.rsv-btns{display:flex; gap:8px; margin-top:11px}
.btn-sm{flex:1; padding:9px 0; border:none; border-radius:9px; font-size:.82em; font-weight:700; cursor:pointer; -webkit-tap-highlight-color:transparent}
.btn-detail{background:#F3E3E7; color:var(--text)}
.btn-edit{background:var(--accent-l); color:var(--accent-d)}
.btn-cxl{background:#fff; color:var(--danger); border:1.5px solid #F3C1BC}
.hist-row{display:flex; gap:10px; padding:10px 2px; border-bottom:1px solid #f0f2f5; font-size:.86em; align-items:center}
.hist-row:last-child{border-bottom:none}
.hist-date{color:var(--muted); flex:0 0 90px}
.hist-menu{font-weight:600; flex:1; min-width:0}
.hist-staff{color:var(--muted); font-size:.9em; font-weight:400}
.hist-btn{flex:0 0 auto; padding:6px 13px; border-radius:8px; font-size:.78em; font-weight:700; border:1.5px solid var(--border); background:#fff; color:var(--muted); cursor:pointer; -webkit-tap-highlight-color:transparent}

/* 詳細モーダル */
.modal-bg{position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:200; display:flex; align-items:flex-end; justify-content:center}
.modal{background:#fff; border-radius:18px 18px 0 0; width:100%; max-width:560px; padding:18px 18px calc(20px + env(safe-area-inset-bottom,0px)); animation:mup .2s ease}
@keyframes mup{from{transform:translateY(30px); opacity:.5} to{transform:none; opacity:1}}
.modal-title{font-weight:800; font-size:1.05em; margin-bottom:8px; display:flex; align-items:center; justify-content:space-between}
.modal-x{background:none; border:none; font-size:1.25em; color:var(--muted); cursor:pointer; padding:4px 8px}

/* フォーム */
label{display:block; font-size:.85em; font-weight:700; color:var(--muted); margin:12px 0 5px}
input,select{
  width:100%; padding:12px; border:1.5px solid var(--border); border-radius:10px;
  font-size:16px; background:#fff; -webkit-appearance:none;
}
input:focus,select:focus{outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(240,97,142,.18)}

/* 確認 */
.cf-row{display:flex; padding:10px 0; border-bottom:1px solid #f0f2f5; font-size:.92em}
.cf-row:last-child{border-bottom:none}
.cf-l{color:var(--muted); flex:0 0 92px}
.cf-v{font-weight:700; flex:1}
.cf-total{font-size:1.2em; color:var(--accent-d)}
.cf-strike{text-decoration:line-through; color:#aaa; font-weight:400; margin-right:6px; font-size:.85em}

/* フッターナビ */
.ft{
  position:fixed; bottom:0; left:0; right:0; background:#fff;
  border-top:1px solid var(--border); padding:12px 14px calc(12px + env(safe-area-inset-bottom,0px));
  display:flex; flex-wrap:wrap; gap:10px; z-index:100; max-width:100%;
}
.ft-summary{
  flex:1 1 100%; text-align:center; font-size:.85em; font-weight:700;
  color:var(--accent-d); background:var(--accent-l); border-radius:8px; padding:7px 8px;
}
.btn{
  flex:1; padding:14px; border:none; border-radius:12px; font-size:1em; font-weight:700;
  cursor:pointer; -webkit-tap-highlight-color:transparent; transition:opacity .15s;
}
.btn:active{opacity:.8}
.btn-p{background:var(--grad); color:#fff; box-shadow:0 3px 10px rgba(240,97,142,.35)}
.btn-s{background:#F3E3E7; color:var(--text); flex:0 0 30%}
.btn-dis{opacity:.4; pointer-events:none}

/* 完了 */
.done-wrap{text-align:center; padding:48px 20px}
.done-ic{width:84px; height:84px; border-radius:50%; background:var(--accent-l); display:flex; align-items:center; justify-content:center; font-size:2.6em; margin:0 auto 18px}
.done-title{font-size:1.25em; font-weight:800; margin-bottom:10px}
.done-note{font-size:.9em; color:var(--muted)}

/* その他 */
.loading{text-align:center; padding:60px 20px; color:var(--muted)}
.spinner{width:36px; height:36px; border:4px solid var(--accent-l); border-top-color:var(--accent); border-radius:50%; margin:0 auto 14px; animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.err-box{background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:10px; padding:12px 14px; font-size:.88em; margin-bottom:12px}
.hidden{display:none}
</style>
</head>
<body>

<div class="hd">
  <div class="hd-title">✂️ HAPTIC ご予約</div>
  <div class="steps" id="stepsBar"></div>
</div>

<div class="main" id="app">
  <div class="loading"><div class="spinner"></div>読み込み中...</div>
</div>

<div class="ft hidden" id="footNav"></div>

<script src="https://static.line-scdn.net/liff/edge/2/sdk.js"></script>
<script>
const LIFF_ID = '<?= htmlspecialchars($liffId, ENT_QUOTES) ?>';
const API = '/api/liff';

let lineUid = '', lineName = '';
let master = null;           // menus, staff, customer, coupons
let step = 'menu';
let sel = { menuIds:[], date:null, time:null, staffId:null, couponId:null };
let cache = { week:{}, staffAt:{} };
let weekOffset = 0;          // HPB風カレンダーの週送り（0〜5）
let editRid = null;          // 予約変更モード：変更対象の予約ID

const STEP_KEYS = () => {
  const s = ['menu','date','staff'];
  if (!editRid && master && master.coupons.length) s.push('coupon'); // 変更時はクーポン選択をスキップ（元の利用予定を引き継ぐ）
  s.push('confirm');
  return s;
};
const STEP_LABELS = { menu:'メニュー', date:'日時', staff:'指名', coupon:'クーポン', confirm:'確認' };

// ── 起動 ──────────────────────────────
(async function init() {
  const params = new URLSearchParams(location.search);
  try {
    if (params.get('debug_uid')) {
      lineUid = params.get('debug_uid');   // ブラウザテスト用
    } else {
      if (!LIFF_ID) { renderSetupNotice(); return; }
      await liff.init({ liffId: LIFF_ID });
      if (!liff.isLoggedIn()) { liff.login(); return; }
      try {
        const prof = await liff.getProfile();
        lineUid  = prof.userId;
        lineName = prof.displayName || '';
        sessionStorage.removeItem('liff_retry');
      } catch (pe) {
        // profileスコープ不足など：LINEアプリ内ならcontextから取得
        const ctx = liff.getContext ? liff.getContext() : null;
        if (ctx && ctx.userId) {
          lineUid = ctx.userId;
        } else if (!sessionStorage.getItem('liff_retry')) {
          // 古いトークンが残っている場合は一度だけ再ログイン（新スコープで再同意）
          sessionStorage.setItem('liff_retry', '1');
          liff.logout();
          liff.login();
          return;
        } else {
          sessionStorage.removeItem('liff_retry');
          throw pe;
        }
      }
    }
    const res = await fetch(`${API}/master.php?line_uid=${encodeURIComponent(lineUid)}`);
    master = await res.json();
    if (!master.success) throw new Error(master.error || '読み込みに失敗しました');

    if (!master.customer || !master.customer.registered) {
      renderRegister();
    } else if (hasMypage()) {
      renderMypage();
    } else {
      go('menu');
    }
  } catch (e) {
    document.getElementById('app').innerHTML =
      `<div class="err-box">読み込みに失敗しました。<br>${esc(e.message||'')}<br>時間をおいて再度お試しください。</div>`;
  }
})();

function renderSetupNotice() {
  document.getElementById('app').innerHTML =
    '<div class="err-box">LIFF IDが未設定です。<br>.envのLIFF_IDを設定してください。<br>（テストは ?debug_uid=Uxxxx で可能）</div>';
}

// ── ステップ制御 ──────────────────────
function go(key) {
  step = key;
  renderSteps();
  ({ menu:renderMenu, date:renderDate, staff:renderStaff, coupon:renderCoupon, confirm:renderConfirm })[key]();
  window.scrollTo(0, 0);
}

function renderSteps() {
  const keys = STEP_KEYS();
  const cur = keys.indexOf(step);
  document.getElementById('stepsBar').innerHTML = keys.map((k, i) => {
    const cls = k === step ? 'st active' : (i < cur ? 'st done' : 'st');
    const n = i < cur ? '✓' : (i + 1);
    return `<div class="${cls}"><div class="st-n">${n}</div><span>${STEP_LABELS[k]}</span></div>` +
           (i < keys.length - 1 ? '<div class="st-sep"></div>' : '');
  }).join('');
}

function foot(html) {
  const ft = document.getElementById('footNav');
  if (!html) { ft.classList.add('hidden'); ft.innerHTML = ''; return; }
  ft.innerHTML = html;
  ft.classList.remove('hidden');
}

// ── 会員登録 ──────────────────────────
function renderRegister() {
  document.getElementById('stepsBar').innerHTML = '';
  document.getElementById('app').innerHTML = `
    <div class="sec-title">はじめまして😊</div>
    <div class="sec-note">ご予約の前に、お客様情報の登録をお願いします（初回のみ）</div>
    <div class="card">
      <label>お名前 <span style="color:var(--danger)">必須</span></label>
      <input type="text" id="regName" placeholder="例：山田 花子" value="">
      <label>お電話番号（任意）</label>
      <input type="tel" id="regPhone" placeholder="例：090-1234-5678">
      <label>誕生日（任意・バースデークーポンをお送りします🎂）</label>
      <div style="display:flex;gap:8px;">
        <select id="regBY" style="flex:1.6"></select>
        <select id="regBM" style="flex:1"></select>
        <select id="regBD" style="flex:1"></select>
      </div>
      <div id="regErr"></div>
    </div>`;
  // 誕生日プルダウン生成（年は2000年を初期選択）
  const by = document.getElementById('regBY');
  const bm = document.getElementById('regBM');
  const bd = document.getElementById('regBD');
  by.innerHTML = '<option value="">年</option>' +
    Array.from({length: 2020 - 1930 + 1}, (_, i) => 2020 - i)
      .map(y => `<option value="${y}" ${y === 2000 ? 'selected' : ''}>${y}年</option>`).join('');
  bm.innerHTML = '<option value="">月</option>' +
    Array.from({length: 12}, (_, i) => `<option value="${i+1}">${i+1}月</option>`).join('');
  bd.innerHTML = '<option value="">日</option>' +
    Array.from({length: 31}, (_, i) => `<option value="${i+1}">${i+1}日</option>`).join('');
  foot(`<button class="btn btn-p" onclick="submitRegister()">登録してご予約へ →</button>`);
}

async function submitRegister() {
  const name = document.getElementById('regName').value.trim();
  if (!name) { document.getElementById('regErr').innerHTML = '<div class="err-box">お名前を入力してください</div>'; return; }
  try {
    const res = await fetch(`${API}/register.php`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        line_uid: lineUid, line_name: lineName, name,
        phone: document.getElementById('regPhone').value.trim(),
        birthdate: (() => {
          const y = document.getElementById('regBY').value;
          const m = document.getElementById('regBM').value;
          const d = document.getElementById('regBD').value;
          return (y && m && d)
            ? `${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`
            : '';
        })(),
      }),
    });
    const d = await res.json();
    if (!d.success) throw new Error(d.error);
    master.customer = { registered: true, name };
    go('menu');
  } catch (e) {
    document.getElementById('regErr').innerHTML = `<div class="err-box">${esc(e.message||'登録に失敗しました')}</div>`;
  }
}

// ── マイページ ─────────────────────────
// 次回のご予約がある場合のみマイページから開始（なければ予約フローへ直行）
function hasMypage() {
  const r = (master && master.reservations) || {};
  return (r.upcoming || []).length > 0;
}

// 履歴・予約があればメニュー画面にマイページへのリンクを出す
function mypageLink() {
  const r = (master && master.reservations) || {};
  if (!(r.upcoming || []).length && !(r.history || []).length) return '';
  return `<div style="text-align:right;margin:-2px 0 8px;">
    <a href="#" onclick="renderMypage();return false;" style="color:var(--accent);font-size:.85em;font-weight:700;text-decoration:none;">📖 マイページを見る</a>
  </div>`;
}

function fmtJpDate(ds) {
  const dows = ['日','月','火','水','木','金','土'];
  const d = new Date(ds + 'T00:00:00');
  return `${d.getMonth()+1}月${d.getDate()}日（${dows[d.getDay()]}）`;
}

function renderMypage() {
  step = 'mypage';
  document.getElementById('stepsBar').innerHTML = '';
  const r = master.reservations || { upcoming: [], history: [] };
  const STATUS = { pending: ['仮予約（確認待ち）','badge-pending'], confirmed: ['ご予約確定','badge-confirmed'] };

  let html = `<div class="mp-hello">👤 ${esc(master.customer.name)}様のマイページ</div>`;

  html += `<div class="cat">📅 次回のご予約</div>`;
  if ((r.upcoming || []).length) {
    html += r.upcoming.map(v => {
      const [label, cls] = STATUS[v.status] || [v.status, 'badge-pending'];
      return `
      <div class="rsv-card">
        <div class="rsv-top">
          <div class="rsv-date">${fmtJpDate(v.date)} ${esc(v.time)}〜</div>
          <span class="badge ${cls}">${label}</span>
        </div>
        <div class="rsv-meta">✂️ ${esc(v.menu)}　👤 ${esc(v.staff)}</div>
        <div class="rsv-btns">
          <button class="btn-sm btn-detail" onclick="showDetail(${v.id},'upcoming')">詳細</button>
          <button class="btn-sm btn-edit" onclick="startEdit(${v.id})">変更</button>
          <button class="btn-sm btn-cxl" onclick="cancelReservation(${v.id})">キャンセル</button>
        </div>
      </div>`;
    }).join('');
    html += `<div class="sec-note">メニュー・日時の変更は「変更」ボタンからどうぞ😊</div>`;
  } else {
    html += `<div class="card" style="text-align:center; color:var(--muted); font-size:.9em">現在のご予約はありません</div>`;
  }

  html += `<div class="cat">📖 過去の来店履歴</div>`;
  if ((r.history || []).length) {
    html += `<div class="card">` + r.history.map(v => `
      <div class="hist-row">
        <div class="hist-date">${fmtJpDate(v.date)}</div>
        <div class="hist-menu">${esc(v.menu)}<span class="hist-staff">　${esc(v.staff)}</span></div>
        <button class="hist-btn" onclick="showDetail(${v.id},'history')">詳細</button>
      </div>`).join('') + `</div>`;
  } else {
    html += `<div class="card" style="text-align:center; color:var(--muted); font-size:.9em">来店履歴はまだありません</div>`;
  }

  document.getElementById('app').innerHTML = html;
  foot(`<button class="btn btn-p" onclick="startBooking()">新しく予約する →</button>`);
  window.scrollTo(0, 0);
}

function startBooking() {
  editRid = null;
  sel = { menuIds:[], date:null, time:null, staffId:null, couponId:null };
  cache = { week:{}, staffAt:{} };
  weekOffset = 0;
  go('menu');
}

// ── 予約変更モード開始 ──
function startEdit(id) {
  const v = ((master.reservations || {}).upcoming || []).find(x => x.id === id);
  if (!v || !v.menu_id) { alert('この予約は画面から変更できません。LINEトークでご連絡ください🙏'); return; }
  editRid = id;
  const ids = (v.menu_ids && v.menu_ids.length) ? v.menu_ids.slice() : [v.menu_id];
  sel = { menuIds: ids, date: v.date, time: v.time, staffId: v.staff_id || 'any', couponId: null };
  cache = { week:{}, staffAt:{} };
  // 現在の予約日が表示される週へ
  const today = new Date(); today.setHours(0,0,0,0);
  const diffDays = Math.floor((new Date(v.date + 'T00:00:00') - today) / 86400000);
  weekOffset = Math.max(0, Math.min(5, Math.floor(diffDays / 7)));
  go('menu');
}

function exitToMypage() {
  editRid = null;
  renderMypage();
}

// ── 予約詳細モーダル ──
function showDetail(id, kind) {
  const v = ((master.reservations || {})[kind] || []).find(x => x.id === id);
  if (!v) return;
  const STATUS_LABEL = { pending:'仮予約（確認待ち）', confirmed:'ご予約確定', completed:'来店済み' };
  const status = kind === 'history' ? '来店済み' : (STATUS_LABEL[v.status] || v.status);
  const timeLabel = v.time + '〜' + (v.end_time || '');

  const rows = [
    ['ステータス', status],
    ['日時', `${fmtJpDate(v.date)} ${timeLabel}`],
    ['メニュー', v.menu + (v.duration ? `（約${v.duration}分）` : '')],
    ['スタイリスト', v.staff],
  ];
  if (v.coupon)        rows.push(['クーポン', '🎫 ' + v.coupon]);
  if (v.price != null) rows.push([kind === 'history' ? '料金' : '予定金額', '¥' + Number(v.price).toLocaleString() + '〜']);

  const bg = document.createElement('div');
  bg.className = 'modal-bg';
  bg.innerHTML = `<div class="modal">
    <div class="modal-title">${kind === 'history' ? 'ご来店の詳細' : 'ご予約詳細'}<button class="modal-x">✕</button></div>
    ${rows.map(([l, val]) => `<div class="cf-row"><div class="cf-l">${l}</div><div class="cf-v">${esc(val)}</div></div>`).join('')}
  </div>`;
  bg.addEventListener('click', e => {
    if (e.target === bg || e.target.classList.contains('modal-x')) bg.remove();
  });
  document.body.appendChild(bg);
}

// ── 予約キャンセル ──
async function cancelReservation(id) {
  const v = ((master.reservations || {}).upcoming || []).find(x => x.id === id);
  if (!v) return;
  if (!confirm(`${fmtJpDate(v.date)} ${v.time}〜\n「${v.menu}」のご予約をキャンセルしますか？`)) return;
  try {
    const res = await fetch(`${API}/cancel.php`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ line_uid: lineUid, reservation_id: id }),
    });
    const d = await res.json();
    if (!d.success) throw new Error(d.error || 'キャンセルに失敗しました');
    await backToMypage();
  } catch (e) {
    alert(e.message || 'キャンセルに失敗しました');
  }
}

async function backToMypage() {
  editRid = null;
  document.getElementById('app').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
  foot('');
  try {
    const res = await fetch(`${API}/master.php?line_uid=${encodeURIComponent(lineUid)}`);
    const d = await res.json();
    if (d.success) master = d;
  } catch (e) { /* 取得失敗時は手元のデータで表示 */ }
  renderMypage();
}

// ── ①メニュー ─────────────────────────
const CAT_ICONS = {'セットメニュー':'💫','カット':'✂️','カラー':'🎨','パーマ':'🌀','縮毛矯正':'💇','トリートメント':'✨','ヘアセット':'👰','ヘッドスパ':'💆','その他':'🧴'};

function renderMenu() {
  const byCat = {};
  master.menus.forEach(m => { (byCat[m.category] = byCat[m.category] || []).push(m); });
  let html = mypageLink() + `<div class="sec-title">メニューをお選びください</div>
    <div class="sec-note">複数メニューを組み合わせて選べます（施術時間・料金は合算されます）</div>`;
  if (editRid) html += `<div class="sec-note">🔁 ご予約の変更中です（変更しない項目はそのまま「次へ」でOK）</div>`;
  for (const [cat, items] of Object.entries(byCat)) {
    html += `<div class="cat">${CAT_ICONS[cat]||'✂️'} ${esc(cat)}</div>`;
    html += items.map(m => `
      <div class="menu-item ${sel.menuIds.includes(m.id)?'sel':''}" onclick="pickMenu(${m.id})">
        <div class="menu-info">
          <div class="menu-name">${esc(m.name)}</div>
          <div class="menu-meta">¥${Number(m.price).toLocaleString()}〜 ／ 約${m.duration_min}分</div>
        </div>
        <div class="menu-radio"></div>
      </div>`).join('');
  }
  document.getElementById('app').innerHTML = html;
  const back = hasMypage() ? `<button class="btn btn-s" onclick="exitToMypage()">← 戻る</button>` : '';
  const cnt = sel.menuIds.length;
  const summary = cnt
    ? `✔ ${cnt}件選択中 ／ 合計 約${totalDuration()}分 ／ ¥${totalPrice().toLocaleString()}〜`
    : 'メニューをタップして選択してください';
  foot(`<div class="ft-summary">${summary}</div>${back}<button class="btn btn-p ${cnt?'':'btn-dis'}" onclick="go('date')">次へ：日時を選ぶ →</button>`);
}

function pickMenu(id) {
  const i = sel.menuIds.indexOf(id);
  if (i >= 0) sel.menuIds.splice(i, 1);
  else {
    if (sel.menuIds.length >= 5) { alert('メニューは5つまでお選びいただけます'); return; }
    sel.menuIds.push(id);
  }
  // 選択が変わったら日時以降をリセット
  sel.date = null; sel.time = null; sel.staffId = null;
  cache.week = {}; cache.staffAt = {};
  weekOffset = 0;
  renderMenu();
}

function selMenus() { return sel.menuIds.map(id => master.menus.find(m => m.id == id)).filter(Boolean); }
function totalDuration() { return selMenus().reduce((s, m) => s + Number(m.duration_min || 0), 0); }
function totalPrice() { return selMenus().reduce((s, m) => s + Number(m.price || 0), 0); }
function menuLabel() { return selMenus().map(m => m.name).join('・'); }

// ── ②日時（HPB風：週×時間マトリクス） ──
function fmtDate(d) {
  return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
}

async function renderDate() {
  const dur = totalDuration();
  const head = `<div class="sec-title">ご希望の日時をお選びください</div>
    <div class="sec-note">✂️ ${esc(menuLabel())}（合計 約${dur}分）</div>`;
  document.getElementById('app').innerHTML = head + '<div class="loading"><div class="spinner"></div></div>';
  foot('');

  // 週の開始日（今日 + weekOffset*7）
  const today = new Date(); today.setHours(0,0,0,0);
  const start = new Date(today); start.setDate(today.getDate() + weekOffset * 7);
  const startStr = fmtDate(start);

  // 1週間分の空きを取得（メニュー合計時間を考慮・変更時は自分の予約を除外）
  const ck = `${sel.menuIds.join('-')}_${startStr}_${editRid || 0}`;
  if (!cache.week[ck]) {
    const res = await fetch(`${API}/slots.php?mode=week&start=${startStr}&duration=${dur}&exclude=${editRid || 0}`);
    const d = await res.json();
    cache.week[ck] = d.success ? d : { days: {}, closed: [], open: '10:00', close: '19:00' };
  }
  const wk   = cache.week[ck];
  const days = wk.days || {};
  const closedSet = new Set(wk.closed || []);

  const dows = ['日','月','火','水','木','金','土'];
  const weekEnd = new Date(start); weekEnd.setDate(start.getDate() + 6);
  const period = `${start.getMonth()+1}月${start.getDate()}日〜${weekEnd.getMonth()+1}月${weekEnd.getDate()}日`;

  // 定休日・休業日の列は非表示
  const dates = [];
  for (let i = 0; i < 7; i++) {
    const d = new Date(start); d.setDate(start.getDate() + i);
    if (!closedSet.has(fmtDate(d))) dates.push(d);
  }

  // ヘッダー行
  let thHtml = '<th class="t-time"></th>';
  dates.forEach(d => {
    const ds = fmtDate(d);
    const w = d.getDay();
    const cls = 't-head' + (d.getTime() === today.getTime() ? ' today' : '') + (sel.date === ds ? ' sel-col' : '');
    const dowCls = 't-dow' + (w === 0 ? ' sun' : w === 6 ? ' sat' : '');
    thHtml += `<th class="${cls}"><div class="t-d">${d.getDate()}</div><div class="${dowCls}">${dows[w]}</div></th>`;
  });

  // 時間行（店舗マスタの営業時間に連動 / 30分刻み）
  const [oh, om] = (wk.open || '10:00').split(':').map(Number);
  const [ch, cm] = (wk.close || '19:00').split(':').map(Number);
  const times = [];
  for (let mm = oh * 60 + om; mm <= ch * 60 + cm - 30; mm += 30) {
    times.push(`${String(Math.floor(mm/60)).padStart(2,'0')}:${String(mm%60).padStart(2,'0')}`);
  }

  let tbody = '';
  times.forEach(t => {
    let row = `<td class="t-time">${t}</td>`;
    dates.forEach(d => {
      const ds = fmtDate(d);
      const avail = (days[ds] || []).includes(t);
      const isSel = sel.date === ds && sel.time === t;
      if (isSel)      row += `<td class="t-cell sel-cell" onclick="selDateTime('${ds}','${t}')">予約</td>`;
      else if (avail) row += `<td class="t-cell avail" onclick="selDateTime('${ds}','${t}')">◎</td>`;
      else            row += `<td class="t-cell past">×</td>`;
    });
    tbody += `<tr>${row}</tr>`;
  });

  const selInfo = sel.date && sel.time
    ? (() => {
        const sd = new Date(sel.date + 'T00:00:00');
        return `<div class="sel-info">✓ ${sd.getMonth()+1}月${sd.getDate()}日（${dows[sd.getDay()]}）${sel.time}〜 を選択中</div>`;
      })()
    : '<div class="sec-note" style="margin-top:10px;text-align:center;">◎をタップして日時をお選びください</div>';

  const tableHtml = dates.length
    ? `<div class="hpb-wrap"><table class="hpb-table"><thead><tr>${thHtml}</tr></thead><tbody>${tbody}</tbody></table></div>`
    : `<div class="card" style="text-align:center;color:var(--muted);font-size:.9em;">この週はすべて休業日です🙏</div>`;

  document.getElementById('app').innerHTML = head + `
    <div class="hpb-nav">
      <button class="nav-btn" onclick="changeWeek(-1)" ${weekOffset <= 0 ? 'disabled' : ''}>‹ 前の週</button>
      <div class="hpb-period">${period}</div>
      <button class="nav-btn" onclick="changeWeek(1)" ${weekOffset >= 5 ? 'disabled' : ''}>次の週 ›</button>
    </div>
    ${tableHtml}
    ${selInfo}`;

  foot(`<button class="btn btn-s" onclick="go('menu')">← 戻る</button>
        <button class="btn btn-p ${sel.date&&sel.time?'':'btn-dis'}" onclick="go('staff')">次へ：指名 →</button>`);
}

function selDateTime(ds, t) {
  if (sel.date === ds && sel.time === t) { sel.date = null; sel.time = null; }  // 再タップで解除
  else { sel.date = ds; sel.time = t; }
  sel.staffId = null;
  renderDate();
}

function changeWeek(d) {
  weekOffset = Math.max(0, Math.min(5, weekOffset + d));
  renderDate();
}

// ── ③スタイリスト ─────────────────────
async function renderStaff() {
  const dur = totalDuration();
  const menuParam = selMenus().map(m => m.name).join('|');
  document.getElementById('app').innerHTML =
    `<div class="sec-title">スタイリストをお選びください</div><div class="loading"><div class="spinner"></div></div>`;
  foot('');

  const key = `${sel.menuIds.join('-')}_${sel.date}_${sel.time}_${editRid || 0}`;
  if (!cache.staffAt[key]) {
    const res = await fetch(`${API}/slots.php?mode=staff&date=${sel.date}&time=${sel.time}&duration=${dur}&menu=${encodeURIComponent(menuParam)}&exclude=${editRid || 0}`);
    const d = await res.json();
    cache.staffAt[key] = d.success ? d.staff : [];
  }
  const list = cache.staffAt[key];

  if (!list.length) {
    document.getElementById('app').innerHTML =
      `<div class="sec-title">スタイリストをお選びください</div>
       <div class="err-box">申し訳ございません、この時間の空きがなくなりました。別の日時をお選びください🙏</div>`;
    foot(`<button class="btn btn-s" onclick="go('date')">← 戻る</button>`);
    return;
  }

  const cards = list.map(s => `
    <div class="staff-card ${sel.staffId==s.id?'sel':''}" onclick="pickStaff(${s.id})">
      ${s.photo_url
        ? `<img class="staff-ph" src="${esc(s.photo_url)}" alt="">`
        : `<div class="staff-ph-none">👤</div>`}
      <div class="staff-name">${esc(s.name)}</div>
    </div>`).join('');

  document.getElementById('app').innerHTML = `
    <div class="sec-title">スタイリストをお選びください</div>
    <div class="staff-grid">
      ${cards}
      <div class="staff-card ${sel.staffId==='any'?'sel':''}" onclick="pickStaff('any')">
        <div class="staff-ph-none">🎲</div>
        <div class="staff-name">おまかせ</div>
      </div>
    </div>`;
  const nxt = (!editRid && master.coupons.length) ? 'coupon' : 'confirm';
  foot(`<button class="btn btn-s" onclick="go('date')">← 戻る</button>
        <button class="btn btn-p ${sel.staffId?'':'btn-dis'}" onclick="go('${nxt}')">次へ →</button>`);
}

function pickStaff(id) {
  sel.staffId = id;
  document.querySelectorAll('.staff-card').forEach(c => c.classList.remove('sel'));
  event.currentTarget.classList.add('sel');
  const btn = document.querySelector('#footNav .btn-p');
  if (btn) btn.classList.remove('btn-dis');
}

// ── ④クーポン ─────────────────────────
function couponLabel(c) {
  return c.discount_type === 'percent' && c.discount_rate
    ? `${c.discount_rate}%OFF`
    : `−¥${Number(c.discount).toLocaleString()}`;
}

function renderCoupon() {
  const cards = master.coupons.map(c => `
    <div class="coupon-card ${sel.couponId==c.id?'sel':''}" onclick="pickCoupon(${c.id})">
      <div class="coupon-name">🎫 ${esc(c.description)}</div>
      <div class="coupon-val">${couponLabel(c)}</div>
      <div class="coupon-exp">${c.expired_at ? '有効期限：' + c.expired_at + 'まで' : '有効期限なし'}</div>
    </div>`).join('');

  document.getElementById('app').innerHTML = `
    <div class="sec-title">ご利用になるクーポン</div>
    <div class="sec-note">お会計時にご提示いただくクーポンです</div>
    ${cards}
    <div class="coupon-card ${sel.couponId===0?'sel':''}" onclick="pickCoupon(0)">
      <div class="coupon-name">クーポンを使わない</div>
    </div>`;
  foot(`<button class="btn btn-s" onclick="go('staff')">← 戻る</button>
        <button class="btn btn-p ${sel.couponId!==null?'':'btn-dis'}" onclick="go('confirm')">次へ：確認 →</button>`);
}

function pickCoupon(id) {
  sel.couponId = id;
  renderCoupon();
}

// ── ⑤確認 ────────────────────────────
function renderConfirm() {
  const ms = selMenus();
  const sumPrice = totalPrice();
  const dows = ['日','月','火','水','木','金','土'];
  const dt = new Date(sel.date + 'T00:00:00');
  const dateLabel = `${dt.getMonth()+1}月${dt.getDate()}日（${dows[dt.getDay()]}）${sel.time}〜`;
  const staffLabel = sel.staffId === 'any' ? 'おまかせ'
    : (master.staff.find(s => s.id == sel.staffId) || {}).name || '';

  const orig = editRid ? ((master.reservations || {}).upcoming || []).find(x => x.id === editRid) : null;
  const coupon = (!editRid && sel.couponId) ? master.coupons.find(c => c.id == sel.couponId) : null;
  let priceHtml;
  if (coupon) {
    const total = coupon.discount_type === 'percent' && coupon.discount_rate
      ? Math.round(sumPrice * (100 - coupon.discount_rate) / 100)
      : Math.max(0, sumPrice - coupon.discount);
    priceHtml = `<span class="cf-strike">¥${sumPrice.toLocaleString()}</span>¥${total.toLocaleString()}〜`;
  } else {
    priceHtml = `¥${sumPrice.toLocaleString()}〜`;
  }

  let couponRow = '';
  if (coupon)                        couponRow = `<div class="cf-row"><div class="cf-l">クーポン</div><div class="cf-v" style="color:var(--coupon)">🎫 ${esc(coupon.description)}（${couponLabel(coupon)}）</div></div>`;
  else if (editRid && orig && orig.coupon) couponRow = `<div class="cf-row"><div class="cf-l">クーポン</div><div class="cf-v" style="color:var(--coupon)">🎫 ${esc(orig.coupon)}（変更後もご利用いただけます）</div></div>`;

  const menuRows = ms.map(m =>
    `<div>${esc(m.name)}<span style="font-weight:400;font-size:.82em;color:var(--muted)">　¥${Number(m.price).toLocaleString()}〜／約${m.duration_min}分</span></div>`
  ).join('');

  document.getElementById('app').innerHTML = `
    <div class="sec-title">${editRid ? 'ご予約変更の確認' : 'ご予約内容の確認'}</div>
    ${editRid && orig ? `<div class="sec-note">変更前：${fmtJpDate(orig.date)} ${esc(orig.time)}〜 ${esc(orig.menu)}</div>` : ''}
    <div class="card">
      <div class="cf-row"><div class="cf-l">お名前</div><div class="cf-v">${esc(master.customer.name)} 様</div></div>
      <div class="cf-row"><div class="cf-l">メニュー</div><div class="cf-v">${menuRows}<div style="font-weight:400;font-size:.82em;color:var(--muted);margin-top:2px;">合計 約${totalDuration()}分</div></div></div>
      <div class="cf-row"><div class="cf-l">日時</div><div class="cf-v">${dateLabel}</div></div>
      <div class="cf-row"><div class="cf-l">スタイリスト</div><div class="cf-v">${esc(staffLabel)}</div></div>
      ${couponRow}
      <div class="cf-row"><div class="cf-l">予定金額</div><div class="cf-v cf-total">${priceHtml}</div></div>
    </div>
    <div class="sec-note">${editRid ? '※変更後は改めてスタッフが確認し、LINEで確定のご連絡をお送りします。' : '※仮予約です。スタッフ確認後、LINEで確定のご連絡をお送りします。'}</div>
    <div id="cfErr"></div>`;
  const backKey = (!editRid && master.coupons.length) ? 'coupon' : 'staff';
  foot(`<button class="btn btn-s" onclick="go('${backKey}')">← 戻る</button>
        <button class="btn btn-p" id="submitBtn" onclick="submitReserve()">${editRid ? 'この内容に変更する' : 'この内容で仮予約する'}</button>`);
}

async function submitReserve() {
  const btn = document.getElementById('submitBtn');
  btn.classList.add('btn-dis'); btn.textContent = '送信中...';
  const payload = {
    line_uid: lineUid,
    menu_ids: sel.menuIds,
    date: sel.date,
    time: sel.time,
    staff_id: sel.staffId,
  };
  if (editRid) payload.reservation_id = editRid;
  else         payload.coupon_id = sel.couponId || null;
  try {
    const res = await fetch(`${API}/${editRid ? 'update.php' : 'reserve.php'}`, {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload),
    });
    const d = await res.json();
    if (!d.success) throw new Error(d.error || (editRid ? '変更に失敗しました' : '予約に失敗しました'));
    renderDone(d);
  } catch (e) {
    alert(e.message || 'エラーが発生しました');  // 他のお客様の予約と重なった場合など
    document.getElementById('cfErr').innerHTML = `<div class="err-box">${esc(e.message)}</div>`;
    btn.classList.remove('btn-dis'); btn.textContent = editRid ? 'この内容に変更する' : 'この内容で仮予約する';
    cache = { week:{}, staffAt:{} };  // 空き情報を再取得させる
  }
}

// ── ⑥完了 ────────────────────────────
function renderDone(d) {
  const edited = !!editRid;
  editRid = null;
  document.getElementById('stepsBar').innerHTML = '';
  document.getElementById('app').innerHTML = `
    <div class="done-wrap">
      <div class="done-ic">${edited ? '🔁' : '✅'}</div>
      <div class="done-title">${edited ? 'ご予約を変更しました' : '仮予約を受け付けました'}</div>
      <div class="card" style="text-align:left; margin-top:18px;">
        <div class="cf-row"><div class="cf-l">日時</div><div class="cf-v">${esc(d.date_label)} ${esc(d.time_label)}</div></div>
        <div class="cf-row"><div class="cf-l">メニュー</div><div class="cf-v">${esc(d.menu)}</div></div>
        <div class="cf-row"><div class="cf-l">スタイリスト</div><div class="cf-v">${esc(d.staff)}</div></div>
      </div>
      <div class="done-note" style="margin-top:14px;">
        スタッフが確認次第、確定のご連絡を<br>LINEでお送りします😊
      </div>
    </div>`;
  foot(`<button class="btn btn-s" onclick="backToMypage()">マイページ</button>
        <button class="btn btn-p" onclick="closeLiff()">閉じる</button>`);
}

function closeLiff() {
  if (typeof liff !== 'undefined' && liff.isInClient && liff.isInClient()) liff.closeWindow();
  else location.reload();
}

function esc(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
