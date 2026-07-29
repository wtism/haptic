<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/db.php';

try {
    $shopName = db()->query('SELECT shop_name FROM shop_settings WHERE id=1')->fetchColumn() ?: 'HAPTIC';
} catch (Throwable $e) {
    $shopName = 'HAPTIC';
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($pageTitle ?? '管理画面') ?> | <?= h($shopName) ?></title>
<style>
/* ===== RESET ===== */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --accent:   #6B9E8A;
    --accent-d: #5a8a77;
    --accent-l: #e8f4f0;
    --nav-bg:   #1e2d3d;
    --bg:       #f4f5f7;
    --card:     #ffffff;
    --text:     #2c3e50;
    --muted:    #6b7280;
    --border:   #e5e7eb;
    --danger:   #e74c3c;
    --warning:  #f59e0b;
    --success:  #10b981;
    --radius:   10px;
    --shadow:   0 1px 4px rgba(0,0,0,0.07), 0 2px 10px rgba(0,0,0,0.04);
    --nav-h:    56px;
    --bot-h:    64px;
}
html { scroll-behavior: smooth; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Hiragino Sans', 'Yu Gothic UI', sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 17px;
    line-height: 1.65;
    -webkit-font-smoothing: antialiased;
    padding-bottom: 52px; /* 固定フッターの高さ分 */
}
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ===== TOP NAV ===== */
.nav {
    background: var(--nav-bg);
    display: flex;
    align-items: center;
    height: var(--nav-h);
    padding: 0 18px;
    position: sticky;
    top: 0;
    z-index: 300;
    box-shadow: 0 2px 12px rgba(0,0,0,0.28);
}
.nav-brand {
    font-size: 1em;
    font-weight: 700;
    color: #a8d5c2;
    margin-right: 16px;
    white-space: nowrap;
    text-decoration: none;
    letter-spacing: 0.05em;
    flex-shrink: 0;
}
.nav-brand:hover { color: #fff; text-decoration: none; }

/* Desktop: nav-menu is horizontal flex row */
.nav-menu {
    display: flex;
    align-items: center;
    gap: 0;
    flex: 1;
}

.nav-link {
    color: #94a3b8;
    padding: 0 13px;
    height: var(--nav-h);
    display: flex;
    align-items: center;
    font-size: 0.875em;
    white-space: nowrap;
    border-bottom: 3px solid transparent;
    transition: color 0.15s, border-color 0.15s;
    text-decoration: none;
}
.nav-link:hover, .nav-link.active { color: #fff; border-bottom-color: var(--accent); text-decoration: none; }

.nav-dropdown { position: relative; }
.nav-dropdown-btn {
    color: #94a3b8;
    padding: 0 13px;
    height: var(--nav-h);
    display: flex;
    align-items: center;
    font-size: 0.875em;
    gap: 4px;
    cursor: pointer;
    border: none;
    border-bottom: 3px solid transparent;
    background: none;
    white-space: nowrap;
    transition: color 0.15s;
}
.nav-dropdown-btn:hover, .nav-dropdown-btn.active { color: #fff; border-bottom-color: #e08850; }
.nav-dropdown-menu {
    display: none;
    position: absolute;
    top: var(--nav-h);
    left: 0;
    background: #1a2b3c;
    min-width: 200px;
    border-radius: 0 0 10px 10px;
    box-shadow: 0 12px 32px rgba(0,0,0,0.35);
    z-index: 400;
    overflow: hidden;
    border-top: 2px solid var(--accent);
}
.nav-dropdown-menu a {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    color: #94a3b8;
    font-size: 0.875em;
    transition: background 0.12s;
    text-decoration: none;
}
.nav-dropdown-menu a:hover { background: rgba(255,255,255,0.07); color: #fff; text-decoration: none; }
.nav-dropdown-menu .divider { border-top: 1px solid rgba(255,255,255,0.07); margin: 3px 0; }
.nav-dropdown:hover .nav-dropdown-menu { display: block; }

.nav-right {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}
.nav-user-name { font-weight: 700; color: #fff; font-size: 0.875em; }
.nav-user-role { font-size: 0.74em; color: #78909c; background: rgba(255,255,255,0.09); padding: 2px 8px; border-radius: 10px; }
.nav-logout { color: #78909c; font-size: 0.8em; text-decoration: none; }
.nav-logout:hover { color: #fff; text-decoration: none; }

/* Hamburger: hidden on desktop */
.nav-toggle { display: none; }

/* ===== SP BOTTOM NAV (hidden on PC) ===== */
.sp-bottom-nav { display: none; }

/* ===== MAIN ===== */
.main { max-width: 1400px; margin: 24px auto; padding: 0 22px; }
.page-title { font-size: 1.2em; font-weight: 700; margin-bottom: 18px; color: var(--text); display: flex; align-items: center; gap: 8px; }

/* ===== CARD ===== */
.card { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 18px; overflow: hidden; }
.card-header {
    padding: 13px 18px;
    border-bottom: 1px solid var(--border);
    font-weight: 600;
    font-size: 0.9em;
    color: var(--muted);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.card-body { padding: 20px; }

/* ===== TABLE ===== */
.table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
th { background: #f8f9fb; padding: 10px 13px; text-align: left; font-weight: 600; color: var(--muted); border-bottom: 2px solid var(--border); white-space: nowrap; }
td { padding: 11px 13px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #f9fafb; }

/* ===== BADGES ===== */
.badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 0.78em; font-weight: 600; white-space: nowrap; }
.badge-pending   { background: #fef3c7; color: #92400e; }
.badge-confirmed { background: #d1fae5; color: #065f46; }
.badge-completed { background: #f3f4f6; color: #374151; }
.badge-cancelled { background: #fee2e2; color: #991b1b; }

/* ===== BUTTONS ===== */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    padding: 9px 18px;
    border-radius: 7px;
    border: none;
    cursor: pointer;
    font-size: 0.875em;
    font-weight: 600;
    transition: opacity 0.15s, transform 0.1s;
    text-decoration: none;
    white-space: nowrap;
    -webkit-tap-highlight-color: transparent;
    line-height: 1;
}
.btn:active { transform: scale(0.96); }
.btn-primary   { background: var(--accent); color: #fff; }
.btn-danger    { background: var(--danger); color: #fff; }
.btn-secondary { background: #64748b; color: #fff; }
.btn-warning   { background: var(--warning); color: #fff; }
.btn-sm { padding: 6px 12px; font-size: 0.82em; border-radius: 6px; }
.btn:hover { opacity: 0.87; text-decoration: none; }

/* ===== FORMS ===== */
.form-group { margin-bottom: 15px; }
label { display: block; font-size: 0.85em; font-weight: 600; color: var(--muted); margin-bottom: 5px; }
input[type=text], input[type=password], input[type=date], input[type=time],
input[type=number], input[type=tel], input[type=email], input[type=url],
select, textarea {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid var(--border);
    border-radius: 7px;
    font-size: 0.9em;
    transition: border-color 0.2s, box-shadow 0.2s;
    background: #fff;
    color: var(--text);
    -webkit-appearance: none;
    appearance: none;
}
input:focus, select:focus, textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(107,158,138,0.15);
}
input[readonly], input[disabled] { background: #f8f9fb; color: var(--muted); }

/* ===== ALERTS ===== */
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 15px; font-size: 0.9em; display: flex; align-items: flex-start; gap: 8px; }
.alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-danger  { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.alert-info    { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
.alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }

/* ===== STAT GRID ===== */
.grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.stat-card {
    background: var(--card);
    border-radius: var(--radius);
    padding: 22px;
    box-shadow: var(--shadow);
    cursor: pointer;
    transition: box-shadow 0.2s, transform 0.15s;
    text-decoration: none;
    display: block;
    color: inherit;
}
.stat-card:hover { box-shadow: 0 6px 22px rgba(0,0,0,0.1); transform: translateY(-2px); text-decoration: none; }
.stat-num { font-size: 2.2em; font-weight: 800; color: var(--accent); line-height: 1; }
.stat-label { font-size: 0.8em; color: var(--muted); margin-top: 6px; }

/* ===== MISC ===== */
.edit-only { display: none !important; }
.edit-mode .edit-only { display: block !important; }
.edit-mode .view-only { display: none !important; }
.field-value { padding: 9px 12px; background: #f8f9fb; border-radius: 7px; font-size: 0.9em; min-height: 38px; }

/* ===== SP GANTT (PC: hidden) ===== */
.sp-gantt { display: none; }
.sp-gantt-staff { border-top: 1px solid var(--border); }
.sp-gantt-staff-hd { display: flex; align-items: center; justify-content: space-between; padding: 11px 16px; background: rgba(0,0,0,0.025); }
.sp-gantt-staff-name { font-weight: 700; font-size: 0.9em; }
.sp-gantt-off-badge { font-size: 0.7em; background: #fca5a5; color: #991b1b; padding: 2px 7px; border-radius: 10px; margin-left: 5px; }
.sp-gantt-empty { padding: 10px 16px 15px; font-size: 0.85em; color: var(--muted); }
.sp-gantt-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 16px;
    border-left: 4px solid var(--accent);
    background: var(--card);
    margin: 6px 12px;
    border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
    text-decoration: none;
    color: var(--text);
}
.sp-gantt-item:hover { background: #f9fafb; text-decoration: none; }
.sp-gantt-time { font-size: 0.78em; color: var(--muted); white-space: nowrap; min-width: 84px; }
.sp-gantt-info { flex: 1; min-width: 0; }
.sp-gantt-customer { font-weight: 700; font-size: 0.9em; }
.sp-gantt-menu { font-size: 0.8em; color: var(--muted); }
.sp-gantt-badge { font-size: 0.72em; padding: 3px 9px; border-radius: 12px; font-weight: 600; white-space: nowrap; flex-shrink: 0; }

/* ==================================
   RESPONSIVE
   ================================== */
@media (max-width: 900px) {
    .nav { padding: 0 14px; }

    .nav-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
        margin-left: auto;
        background: none;
        border: none;
        color: #fff;
        font-size: 1.6em;
        line-height: 1;
        cursor: pointer;
        width: 44px;
        height: var(--nav-h);
        -webkit-tap-highlight-color: transparent;
        flex-shrink: 0;
    }

    /* nav-menu: モバイルでは非表示 → open時に全画面（白背景） */
    .nav-menu {
        display: none;
        position: fixed;
        top: var(--nav-h);
        left: 0;
        right: 0;
        bottom: 0;
        background: #fff;
        flex-direction: column;
        align-items: stretch;
        overflow-y: auto;
        z-index: 500;
        padding-bottom: 80px;
    }
    .nav.open .nav-menu { display: flex; }

    .nav-link, .nav-dropdown-btn {
        height: 54px;
        width: 100%;
        justify-content: flex-start;
        padding: 0 20px;
        font-size: 0.95em;
        color: #4b5563;
        border-bottom: 1px solid #f0f2f5;
        border-left: none;
        border-right: none;
        border-top: none;
    }
    .nav-link:hover, .nav-dropdown-btn:hover { color: var(--accent-d); border-bottom-color: #f0f2f5; }
    .nav-link.active { background: var(--accent-l); color: var(--accent-d); border-bottom-color: #f0f2f5; }
    .nav-dropdown-btn.active { color: var(--accent-d); border-bottom-color: #f0f2f5; }
    .nav-dropdown { width: 100%; }
    .nav-dropdown-menu,
    .nav-dropdown:hover .nav-dropdown-menu {
        display: block;
        position: static;
        min-width: 0;
        box-shadow: none;
        border-radius: 0;
        background: #f8f9fb;
        border-top: none;
    }
    .nav-dropdown-menu a { padding: 13px 20px 13px 40px; font-size: 0.9em; color: #6b7280; }
    .nav-dropdown-menu a:hover { background: var(--accent-l); color: var(--accent-d); }
    .nav-dropdown-menu .divider { border-top: 1px solid #eceef2; }
    .nav-right {
        flex-direction: row;
        justify-content: space-between;
        width: 100%;
        margin-left: 0;
        padding: 16px 20px;
        border-top: 1px solid #e5e7eb;
    }
    .nav-user-name { color: var(--text); }
    .nav-user-role { color: #6b7280; background: #f0f2f5; }
    .nav-logout { color: #9ca3af; }
    .nav-logout:hover { color: var(--danger); }
}

@media (max-width: 768px) {
    body {
        font-size: 17px;
        padding-bottom: calc(var(--bot-h) + env(safe-area-inset-bottom, 0px));
    }

    .sp-bottom-nav {
        display: flex;
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        height: var(--bot-h);
        background: var(--nav-bg);
        border-top: 1px solid rgba(255,255,255,0.08);
        z-index: 400;
        padding-bottom: env(safe-area-inset-bottom, 0px);
        box-shadow: 0 -2px 16px rgba(0,0,0,0.22);
    }
    .sp-bottom-nav a {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 3px;
        color: #6b7c8a;
        font-size: 0.65em;
        font-weight: 600;
        text-decoration: none;
        padding: 6px 2px 2px;
        transition: color 0.15s;
        -webkit-tap-highlight-color: transparent;
        border-top: 2px solid transparent;
    }
    .sp-bottom-nav a .spni { font-size: 1.9em; line-height: 1; }
    .sp-bottom-nav a.active { color: var(--accent); border-top-color: var(--accent); }
    .sp-bottom-nav a:hover { color: var(--accent); text-decoration: none; }

    .main { margin: 12px auto; padding: 0 12px; }
    .page-title { font-size: 1.1em; margin-bottom: 12px; }
    .card-body { padding: 14px; }
    .card-header { padding: 11px 14px; }
    .card { margin-bottom: 12px; }

    .grid-3 { grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .stat-card { padding: 14px 10px; }
    .stat-num { font-size: 1.7em; }
    .stat-label { font-size: 0.74em; }

    input[type=text], input[type=password], input[type=date], input[type=time],
    input[type=number], input[type=tel], input[type=email], input[type=url],
    select, textarea { font-size: 16px; }

    .btn { min-height: 42px; }
    .btn-sm { min-height: 34px; }

    .modal-outer { align-items: flex-end !important; justify-content: stretch !important; }
    .modal-inner {
        width: 100% !important;
        max-width: 100% !important;
        border-radius: 18px 18px 0 0 !important;
        margin: 0 !important;
        max-height: 92dvh;
        overflow-y: auto;
    }

    /* 全ページのインラインstyleモーダルを一括SP対応（下からスライドアップ） */
    div[id*="odal"][style*="position:fixed"] { align-items: flex-end !important; }
    div[id*="odal"][style*="position:fixed"] > div {
        width: 100% !important;
        max-width: 100% !important;
        border-radius: 18px 18px 0 0 !important;
        margin: 0 !important;
        max-height: 92dvh !important;
        overflow-y: auto !important;
    }

    /* テーブルの横はみ出し防止（カード内で横スクロール） */
    .card table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; width: 100%; }

    /* 固定フッターはSPでは非表示（ボトムナビと重なるため） */
    footer { display: none !important; }
    body { padding-bottom: calc(var(--bot-h) + env(safe-area-inset-bottom, 0px)); }

    .pc-gantt { display: none !important; }
    .sp-gantt { display: block !important; }
}
</style>
</head>
<body>

<nav class="nav" id="mainNav">
    <a href="<?= adminUrl('dashboard.php') ?>" class="nav-brand">✂️ <?= h($shopName) ?></a>

    <div class="nav-menu">
        <a href="<?= adminUrl('dashboard.php') ?>"    class="nav-link <?= $currentPage==='dashboard.php'   ?'active':'' ?>">📅 ダッシュボード</a>
        <a href="<?= adminUrl('reservations.php') ?>"  class="nav-link <?= $currentPage==='reservations.php' ?'active':'' ?>">📋 予約一覧</a>
        <a href="<?= adminUrl('customers.php') ?>"     class="nav-link <?= $currentPage==='customers.php'    ?'active':'' ?>">👥 お客様</a>

        <div class="nav-dropdown">
            <button class="nav-dropdown-btn <?= in_array($currentPage,['shop.php','staff.php','staff_schedule.php','menus.php','coupon_templates.php','products.php','line_templates.php'])?'active':'' ?>">
                ⚙️ マスタ <span style="font-size:0.7em;opacity:0.7;">▼</span>
            </button>
            <div class="nav-dropdown-menu">
                <a href="<?= adminUrl('shop.php') ?>">🏪 店舗設定</a>
                <div class="divider"></div>
                <a href="<?= adminUrl('staff.php') ?>">👤 スタッフ</a>
                <a href="<?= adminUrl('staff_schedule.php') ?>" style="padding-left:32px;font-size:0.82em;opacity:0.78;">└ 📅 休日カレンダー</a>
                <a href="<?= adminUrl('menus.php') ?>">✂️ 施術メニュー</a>
                <a href="<?= adminUrl('coupon_templates.php') ?>">🎫 クーポン</a>
                <a href="<?= adminUrl('products.php') ?>">📦 商品・資材</a>
                <a href="<?= adminUrl('line_templates.php') ?>">📱 LINEテンプレート</a>
            </div>
        </div>

        <div class="nav-dropdown">
            <button class="nav-dropdown-btn <?= in_array($currentPage,['monthly.php','stock.php','sales_list.php','staff_sales.php'])?'active':'' ?>">
                📊 集計 <span style="font-size:0.7em;opacity:0.7;">▼</span>
            </button>
            <div class="nav-dropdown-menu">
                <a href="<?= adminUrl('monthly.php') ?>">📊 日時集計</a>
                <a href="<?= adminUrl('sales_list.php') ?>">📋 施術・物販一覧</a>
                <a href="<?= adminUrl('staff_sales.php') ?>">👤 スタッフ別売上</a>
                <a href="<?= adminUrl('stock.php') ?>">📦 在庫管理</a>
            </div>
        </div>

        <a href="<?= adminUrl('broadcast.php') ?>" class="nav-link <?= $currentPage==='broadcast.php'?'active':'' ?>">📢 配信</a>

        <div class="nav-right">
            <div style="display:flex;align-items:center;gap:8px;">
                <?php
                $_staffId = $_SESSION['staff_id'] ?? null;
                if ($_staffId) {
                    try {
                        $_s = db()->prepare('SELECT photo_url FROM staff WHERE id = ?');
                        $_s->execute([$_staffId]);
                        $_photo = $_s->fetchColumn();
                        if ($_photo): ?>
                        <img src="<?= h($_photo) ?>" style="width:30px;height:30px;border-radius:50%;object-fit:cover;">
                        <?php endif;
                    } catch (Throwable $_e) {}
                } ?>
                <div>
                    <div class="nav-user-name"><?= h(currentAdminName()) ?></div>
                    <div class="nav-user-role"><?= h($_SESSION['admin_role'] ?? '') ?></div>
                </div>
            </div>
            <a href="<?= adminUrl('index.php') ?>?logout=1" class="nav-logout">ログアウト</a>
        </div>
    </div><!-- /.nav-menu -->

    <button class="nav-toggle" aria-label="メニュー"
        onclick="document.getElementById('mainNav').classList.toggle('open')">☰</button>
</nav>

<nav class="sp-bottom-nav">
    <a href="<?= adminUrl('dashboard.php') ?>"    class="<?= $currentPage==='dashboard.php'   ?'active':'' ?>"><span class="spni">📅</span>今日</a>
    <a href="<?= adminUrl('reservations.php') ?>"  class="<?= $currentPage==='reservations.php' ?'active':'' ?>"><span class="spni">📋</span>予約</a>
    <a href="<?= adminUrl('customers.php') ?>"     class="<?= $currentPage==='customers.php'    ?'active':'' ?>"><span class="spni">👥</span>お客様</a>
    <a href="<?= adminUrl('monthly.php') ?>"       class="<?= $currentPage==='monthly.php'      ?'active':'' ?>"><span class="spni">📊</span>集計</a>
    <a href="<?= adminUrl('broadcast.php') ?>"     class="<?= $currentPage==='broadcast.php'    ?'active':'' ?>"><span class="spni">📢</span>配信</a>
</nav>

<script>
document.addEventListener('click', function(e) {
    var nav = document.getElementById('mainNav');
    if (nav.classList.contains('open') && !nav.contains(e.target)) {
        nav.classList.remove('open');
    }
});
</script>

<div class="main">
