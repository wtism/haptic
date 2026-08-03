<?php
// 施術メニュー入れ替え（2026-08-03）
// 既存メニューを全件非アクティブ化し、手書きメニュー表の12件を新規登録する
require_once '/home/mogans/www/haptic.irodori.tokyo/config/db.php';

$NEW = [
    ['カット',        'カット',                    50,  6500],
    ['カラー',        'カラー リタッチ',            60,  6500],
    ['カラー',        'カラー 全体',                60,  6700],
    ['カラー',        'カラー Tゾーン',             60,     0],
    ['カラー',        'カラー ハイライト',          90,     0],
    ['カラー',        'カラー ブリーチ',           120,     0],
    ['パーマ',        'パーマ',                   120, 13800],
    ['パーマ',        '特殊パーマ',                180,     0],
    ['ストレート',    '縮毛矯正',                  180, 21000],
    ['ストレート',    '髪質改善',                  180,     0],
    ['トリートメント', 'コンポジオトリートメント',     5,  3900],
    ['トリートメント', 'カスタマートリートメント',    10,     0],
];

$d = db();

// 冪等性チェック：新メニュー12件が既に「有効」で揃っているなら実行済みとみなす
// （旧「コンポジオトリートメント」は非アクティブ化される側なので重複扱いしない）
$names = array_column($NEW, 1);
$in    = implode(',', array_fill(0, count($names), '?'));
$stmt  = $d->prepare("SELECT COUNT(*) FROM menus WHERE is_active = 1 AND name IN ($in)");
$stmt->execute($names);
if ((int)$stmt->fetchColumn() === count($names)) {
    echo "ABORT: 新メニューは既に登録済みです（実行済み）" . PHP_EOL;
    exit(1);
}

$d->beginTransaction();
try {
    $deact = $d->exec('UPDATE menus SET is_active = 0 WHERE is_active = 1');
    echo "非アクティブ化: {$deact}件" . PHP_EOL;

    $ins = $d->prepare('INSERT INTO menus (category, name, duration_min, price, is_active, display_order) VALUES (?,?,?,?,1,?)');
    $order = 0;
    foreach ($NEW as [$cat, $name, $min, $price]) {
        $order++;
        $ins->execute([$cat, $name, $min, $price, $order]);
        printf("  +[%d] %-12s %-24s %3d分 ¥%s" . PHP_EOL, $d->lastInsertId(), $cat, $name, $min, number_format($price));
    }
    $d->commit();
    echo "COMMIT OK" . PHP_EOL;
} catch (Throwable $e) {
    $d->rollBack();
    echo "ROLLBACK: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo PHP_EOL . '=== 登録後の有効メニュー ===' . PHP_EOL;
foreach ($d->query('SELECT id, category, name, duration_min, price FROM menus WHERE is_active = 1 ORDER BY display_order') as $r) {
    printf("[%d] %-12s %-24s %3d分 ¥%s" . PHP_EOL, $r['id'], $r['category'], $r['name'], $r['duration_min'], number_format($r['price']));
}
echo '非アクティブ: ' . $d->query('SELECT COUNT(*) FROM menus WHERE is_active = 0')->fetchColumn() . '件' . PHP_EOL;
