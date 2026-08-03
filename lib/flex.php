<?php
// ============================================================
// lib/flex.php  - Flexメッセージ生成
// ============================================================

/**
 * クーポンカード（QR付き）- 管理画面からの個別送信・誕生日クーポン等で使用
 */
function flexCouponCard(array $coupon, ?string $qrUrl, string $title = '🎫 クーポン'): array
{
    $discountLabel = ($coupon['discount_type'] ?? 'amount') === 'percent'
        ? ($coupon['discount_rate'] ?? 0) . '% OFF'
        : '¥' . number_format($coupon['discount'] ?? 0) . ' OFF';

    $body = [
        ['type' => 'text', 'text' => $coupon['description'] ?? 'クーポン', 'weight' => 'bold', 'size' => 'lg', 'wrap' => true],
        ['type' => 'separator', 'margin' => 'md'],
        ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
         'contents' => [
            ['type' => 'text', 'text' => '💰 割引', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
            ['type' => 'text', 'text' => $discountLabel, 'size' => 'sm', 'weight' => 'bold', 'flex' => 3],
         ]],
    ];
    if (!empty($coupon['expired_at'])) {
        $body[] = ['type' => 'box', 'layout' => 'horizontal', 'margin' => 'sm',
             'contents' => [
                ['type' => 'text', 'text' => '📅 有効期限', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                ['type' => 'text', 'text' => date('Y年m月d日', strtotime($coupon['expired_at'])) . 'まで', 'size' => 'sm', 'weight' => 'bold', 'flex' => 3],
             ]];
    }
    $body[] = ['type' => 'separator', 'margin' => 'md'];
    if ($qrUrl) {
        $body[] = ['type' => 'image', 'url' => $qrUrl, 'size' => 'md', 'aspectRatio' => '1:1', 'aspectMode' => 'fit', 'margin' => 'md', 'align' => 'center'];
        $body[] = ['type' => 'text', 'text' => 'ご来店時にQRコードをスタッフにご提示ください', 'size' => 'xs', 'color' => '#888888', 'wrap' => true, 'align' => 'center', 'margin' => 'sm'];
    }
    $body[] = ['type' => 'text', 'text' => $coupon['code'] ?? '', 'size' => 'lg', 'weight' => 'bold', 'align' => 'center', 'margin' => 'md', 'color' => '#E8746A'];

    return [
        'type'    => 'flex',
        'altText' => "{$title}（" . ($coupon['code'] ?? '') . '）',
        'contents' => [
            'type'   => 'bubble',
            'styles' => ['header' => ['backgroundColor' => '#E8746A']],
            'header' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'text', 'text' => $title, 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'xl'],
                ],
            ],
            'body' => ['type' => 'box', 'layout' => 'vertical', 'contents' => $body],
        ],
    ];
}

/**
 * LIFF予約画面への誘導ボタン
 */
function flexLiffBooking(string $liffUrl): array
{
    // LIFF予約画面と同じピンク×オレンジ配色
    return [
        'type' => 'flex', 'altText' => 'ご予約はこちらから',
        'contents' => [
            'type' => 'bubble',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#F0618E', 'paddingAll' => 'lg',
                'contents' => [
                    ['type' => 'text', 'text' => '✂️ HAPTIC ご予約', 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'lg'],
                    ['type' => 'text', 'text' => 'メニュー・日時・スタイリストを選ぶだけ♪', 'color' => '#FFE3EC', 'size' => 'xs', 'margin' => 'sm'],
                ],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#FDF6F4',
                'contents' => [
                    ['type' => 'text', 'text' => '下のボタンから予約画面を開いてください😊', 'size' => 'sm', 'color' => '#93767F', 'wrap' => true],
                ],
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#FDF6F4', 'paddingAll' => 'lg',
                'contents' => [[
                    'type' => 'button', 'style' => 'primary', 'color' => '#F79A4D', 'height' => 'md',
                    'action' => ['type' => 'uri', 'label' => '📅 予約画面を開く', 'uri' => $liffUrl],
                ]],
            ],
        ],
    ];
}

/**
 * 日付選択 Flexメッセージ（週単位グリッド・3週分カルーセル）
 */
function flexDateSelect(array $availableDates, int $weekOffset = 0, int $weeks = 4): array
{
    $startDay  = $weekOffset * 7;
    $showDays  = 7;
    $today     = mktime(0,0,0);
    $dowLabels = ['日','月','火','水','木','金','土'];
    $rows = [];

    for ($i = $startDay; $i < $startDay + $showDays; $i++) {
        $ts      = $today + $i * 86400;
        $dateStr = date('Y-m-d', $ts);
        $dow     = (int)date('w', $ts);
        $label   = date('m月d日', $ts) . '（' . $dowLabels[$dow] . '）';
        $isTue = isRegularHoliday($dateStr) || isShopHoliday($dateStr);

        if ($isTue) {
            $rows[] = [
                'type' => 'box', 'layout' => 'horizontal', 'paddingAll' => 'sm',
                'contents' => [
                    ['type'=>'text','text'=>$label,'size'=>'sm','color'=>'#AAAAAA','flex'=>4,'gravity'=>'center'],
                    ['type'=>'text','text'=>'定休日','size'=>'sm','color'=>'#AAAAAA','flex'=>2,'align'=>'end','gravity'=>'center'],
                ],
            ];
        } elseif (isset($availableDates[$dateStr])) {
            $rows[] = [
                'type'   => 'button', 'style' => 'primary', 'color' => '#6B9E8A',
                'height' => 'sm', 'margin' => 'xs',
                'action' => ['type'=>'message','label'=>$label.' ◎','text'=>$dateStr.'で'],
            ];
        } else {
            $rows[] = [
                'type' => 'box', 'layout' => 'horizontal', 'paddingAll' => 'sm',
                'contents' => [
                    ['type'=>'text','text'=>$label,'size'=>'sm','color'=>'#AAAAAA','flex'=>4,'gravity'=>'center'],
                    ['type'=>'text','text'=>'×','size'=>'sm','color'=>'#CCCCCC','flex'=>2,'align'=>'end','gravity'=>'center'],
                ],
            ];
        }
    }

    // 前の7日（2ページ目以降）
    if ($weekOffset > 0) {
        array_unshift($rows, [
            'type'   => 'button', 'style' => 'secondary',
            'height' => 'sm', 'margin' => 'xs',
            'action' => ['type'=>'message','label'=>'← 前の7日','text'=>'カレンダー:'.($weekOffset-1)],
        ]);
    }

    // 次の7日
    $rows[] = [
        'type'   => 'button', 'style' => 'secondary',
        'height' => 'sm', 'margin' => 'md',
        'action' => ['type'=>'message','label'=>'次の7日を見る →','text'=>'カレンダー:'.($weekOffset+1)],
    ];

    $startLabel = date('m月d日', $today + $startDay * 86400);
    $endLabel   = date('m月d日', $today + ($startDay + $showDays - 1) * 86400);

    $bubble = [
        'type' => 'bubble',
        'header' => [
            'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#6B9E8A', 'paddingAll' => 'md',
            'contents' => [[
                'type' => 'text', 'text' => '📅 ' . $startLabel . '〜' . $endLabel,
                'color' => '#ffffff', 'weight' => 'bold', 'size' => 'md',
            ]],
        ],
        'body' => [
            'type' => 'box', 'layout' => 'vertical', 'spacing' => 'xs', 'paddingAll' => 'md',
            'contents' => $rows,
        ],
        'footer' => [
            'type' => 'box', 'layout' => 'vertical',
            'contents' => [[
                'type' => 'button', 'style' => 'secondary', 'height' => 'sm',
                'action' => ['type' => 'message', 'label' => '◀ メニューを選び直す', 'text' => '戻る:メニュー'],
            ]],
        ],
    ];

    return [
        'type'     => 'flex',
        'altText'  => 'ご希望の日付をお選びください',
        'contents' => $bubble,
    ];
}

function flexTimeSelect(string $date, string $staffName, array $slots): array
{
    $dow       = ['日','月','火','水','木','金','土'][date('w', strtotime($date))];
    $dateLabel = date('m月d日（' . $dow . '）', strtotime($date));

    $buttons = [];
    foreach (array_slice($slots, 0, 10) as $slot) {
        $buttons[] = [
            'type' => 'button', 'style' => 'primary', 'color' => '#6B9E8A', 'height' => 'sm',
            'action' => ['type' => 'message', 'label' => $slot . '〜', 'text' => $slot . 'で'],
            'margin' => 'xs',
        ];
    }

    $nameText = $staffName ? "👤 {$staffName}" : '👤 空き時間';

    return [
        'type' => 'flex', 'altText' => "{$dateLabel}の空き時間",
        'contents' => [
            'type' => 'bubble',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#6B9E8A',
                'contents' => [
                    ['type' => 'text', 'text' => "📅 {$dateLabel}", 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'md'],
                    ['type' => 'text', 'text' => $nameText, 'color' => '#ffffff', 'size' => 'sm', 'margin' => 'xs'],
                ],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => array_merge(
                    [['type' => 'text', 'text' => 'ご希望の時間をお選びください', 'size' => 'sm', 'color' => '#888888']],
                    [['type' => 'separator', 'margin' => 'md']],
                    $buttons
                ),
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [[
                    'type' => 'button', 'style' => 'secondary', 'height' => 'sm',
                    'action' => ['type' => 'message', 'label' => '◀ ひとつ前に戻る', 'text' => '戻る:日付'],
                ]],
            ],
        ],
    ];
}

/**
 * スタッフ選択 Flexメッセージ（写真カルーセル）
 */
function flexStaffSelect(array $staffList): array
{
    $bubbles = [];
    foreach ($staffList as $s) {
        $name     = is_array($s) ? $s['name'] : $s;
        $photoUrl = is_array($s) ? ($s['photo_url'] ?? null) : null;
        // URLの妥当性チェック（httpで始まらない場合は無効）
        if ($photoUrl && !str_starts_with($photoUrl, 'http')) $photoUrl = null;

        $photoBox = $photoUrl
            ? ['type' => 'image', 'url' => $photoUrl, 'size' => 'full', 'aspectRatio' => '1:1', 'aspectMode' => 'cover']
            : ['type' => 'box', 'layout' => 'vertical', 'height' => '100px', 'backgroundColor' => '#E8F5F0',
               'contents' => [['type' => 'text', 'text' => '👤', 'size' => 'xxl', 'align' => 'center', 'gravity' => 'center']]];

        $bubbles[] = [
            'type' => 'bubble', 'size' => 'micro',
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    $photoBox,
                    ['type' => 'text', 'text' => $name, 'weight' => 'bold', 'size' => 'md', 'align' => 'center', 'margin' => 'md'],
                ],
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [[
                    'type' => 'button', 'style' => 'primary', 'color' => '#6B9E8A',
                    'action' => ['type' => 'message', 'label' => 'この方で', 'text' => $name . 'さんで'],
                ]],
            ],
        ];
    }

    // 指名なし
    $bubbles[] = [
        'type' => 'bubble', 'size' => 'micro',
        'body' => [
            'type' => 'box', 'layout' => 'vertical',
            'contents' => [
                ['type' => 'box', 'layout' => 'vertical', 'height' => '100px', 'backgroundColor' => '#F5F5F5',
                 'contents' => [['type' => 'text', 'text' => '🎲', 'size' => 'xxl', 'align' => 'center', 'gravity' => 'center']]],
                ['type' => 'text', 'text' => 'おまかせ', 'weight' => 'bold', 'size' => 'md', 'align' => 'center', 'margin' => 'md'],
            ],
        ],
        'footer' => [
            'type' => 'box', 'layout' => 'vertical',
            'contents' => [['type' => 'button', 'style' => 'secondary', 'action' => ['type' => 'message', 'label' => '指名なし', 'text' => '指名なし']]],
        ],
    ];

    // 「ひとつ前に戻る」バブル
    $bubbles[] = [
        'type' => 'bubble', 'size' => 'micro',
        'body' => [
            'type' => 'box', 'layout' => 'vertical', 'justifyContent' => 'center',
            'height' => '140px',
            'contents' => [
                ['type' => 'text', 'text' => '◀', 'size' => 'xl', 'align' => 'center'],
                ['type' => 'text', 'text' => '時間を
変える', 'size' => 'sm', 'align' => 'center', 'wrap' => true, 'margin' => 'sm'],
            ],
        ],
        'footer' => [
            'type' => 'box', 'layout' => 'vertical',
            'contents' => [[
                'type' => 'button', 'style' => 'secondary', 'height' => 'sm',
                'action' => ['type' => 'message', 'label' => '◀ 戻る', 'text' => '戻る:時間'],
            ]],
        ],
    ];

    return [
        'type' => 'flex', 'altText' => 'スタッフをお選びください',
        'contents' => ['type' => 'carousel', 'contents' => $bubbles],
    ];
}

/**
 * メニュー選択 Flexメッセージ（カテゴリ別カルーセル）
 */
function flexMenuSelect(array $menus): array
{
    // カテゴリごとにグループ化
    $byCat = [];
    foreach ($menus as $m) {
        $cat = $m['category'] ?? 'メニュー';
        $byCat[$cat][] = $m;
    }

    $catIcons = [
        'セットメニュー' => '💫', 'カット' => '✂️', 'カラー' => '🎨',
        'パーマ' => '🌀', '縮毛矯正' => '💇', 'トリートメント' => '✨',
        'ヘアセット' => '👰', 'ヘッドスパ' => '💆', 'その他' => '🧴',
    ];

    $bubbles = [];
    foreach ($byCat as $cat => $items) {
        $buttons = [];
        foreach (array_slice($items, 0, 10) as $m) {
            $buttons[] = [
                'type' => 'button', 'style' => 'primary', 'color' => '#6B9E8A', 'height' => 'sm',
                'action' => ['type' => 'message', 'label' => $m['name'], 'text' => $m['name'] . 'で'],
                'margin' => 'sm',
            ];
            $buttons[] = [
                'type' => 'text',
                'text' => '¥' . number_format($m['price']) . '〜 / 約' . $m['duration_min'] . '分',
                'size' => 'xxs', 'color' => '#999999', 'align' => 'center', 'margin' => 'xs',
            ];
        }
        $icon = $catIcons[$cat] ?? '✂️';
        $bubbles[] = [
            'type' => 'bubble', 'size' => 'kilo',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#6B9E8A', 'paddingAll' => 'md',
                'contents' => [['type' => 'text', 'text' => $icon . ' ' . $cat, 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'md']],
            ],
            'body' => ['type' => 'box', 'layout' => 'vertical', 'paddingAll' => 'md', 'contents' => $buttons],
        ];
        if (count($bubbles) >= 10) break; // カルーセル上限対策
    }

    return [
        'type' => 'flex', 'altText' => 'ご希望のメニューをお選びください',
        'contents' => ['type' => 'carousel', 'contents' => $bubbles],
    ];
}

/**
 * クーポン選択 Flexメッセージ
 */
function flexCouponSelect(array $coupons): array
{
    $rows = [];
    foreach (array_slice($coupons, 0, 5) as $c) {
        if (($c['discount_type'] ?? 'amount') === 'percent' && !empty($c['discount_rate'])) {
            $discountLabel = $c['discount_rate'] . '%OFF';
        } else {
            $discountLabel = '−¥' . number_format($c['discount']);
        }
        $expiry = $c['expired_at'] ? date('m/d', strtotime($c['expired_at'])) . 'まで' : '期限なし';

        $rows[] = ['type' => 'separator', 'margin' => 'md'];
        $rows[] = [
            'type' => 'box', 'layout' => 'vertical', 'margin' => 'md',
            'contents' => [
                ['type' => 'text', 'text' => '🎫 ' . $c['description'], 'weight' => 'bold', 'size' => 'sm', 'wrap' => true],
                ['type' => 'text', 'text' => $discountLabel . '（' . $expiry . '）', 'size' => 'xs', 'color' => '#E8746A', 'margin' => 'xs'],
            ],
        ];
        $rows[] = [
            'type' => 'button', 'style' => 'primary', 'color' => '#E8746A', 'height' => 'sm', 'margin' => 'sm',
            'action' => ['type' => 'message', 'label' => 'このクーポンを使う', 'text' => 'クーポン:' . $c['code']],
        ];
    }

    return [
        'type' => 'flex', 'altText' => 'ご利用可能なクーポンがあります',
        'contents' => [
            'type' => 'bubble',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#E8746A', 'paddingAll' => 'md',
                'contents' => [
                    ['type' => 'text', 'text' => '🎫 クーポンが使えます！', 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'md'],
                ],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => array_merge(
                    [['type' => 'text', 'text' => 'ご利用になるクーポンをお選びください', 'size' => 'sm', 'color' => '#888888', 'wrap' => true]],
                    $rows
                ),
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical', 'spacing' => 'sm',
                'contents' => [
                    ['type' => 'button', 'style' => 'secondary',
                     'action' => ['type' => 'message', 'label' => 'クーポンを使わない', 'text' => 'クーポンなし']],
                    ['type' => 'button', 'style' => 'secondary', 'height' => 'sm',
                     'action' => ['type' => 'message', 'label' => '◀ ひとつ前に戻る', 'text' => '戻る:スタッフ']],
                ],
            ],
        ],
    ];
}

/**
 * 予約確認 Flexメッセージ
 */
function flexBookingConfirm(array $data, string $customerName): array
{
    $date      = $data['date'] ?? '';
    $dow       = $date ? ['日','月','火','水','木','金','土'][date('w', strtotime($date))] : '';
    $dateLabel = $date ? date('m月d日（' . $dow . '）', strtotime($date)) : '未定';
    $time      = $data['time']  ?? '';
    $menu      = $data['menu']  ?? '';
    $staff     = ($data['staff'] ?? '') === '指名なし' ? 'おまかせ' : ($data['staff'] ?? 'おまかせ');

    $rows = [
        ['type' => 'text', 'text' => "{$customerName}様", 'weight' => 'bold', 'size' => 'lg'],
        ['type' => 'separator', 'margin' => 'md'],
        flexRow('✂️ メニュー', $menu),
        flexRow('📅 日時',     "{$dateLabel} {$time}〜"),
        flexRow('👤 担当',     $staff),
    ];

    // 料金・クーポン表示
    $price = isset($data['price']) ? (int)$data['price'] : null;
    if (!empty($data['coupon_code'])) {
        if (($data['coupon_discount_type'] ?? 'amount') === 'percent' && !empty($data['coupon_rate'])) {
            $discountLabel = $data['coupon_rate'] . '%OFF';
            $total = $price !== null ? (int)round($price * (100 - $data['coupon_rate']) / 100) : null;
        } else {
            $discountLabel = '−¥' . number_format($data['coupon_discount'] ?? 0);
            $total = $price !== null ? max(0, $price - (int)($data['coupon_discount'] ?? 0)) : null;
        }
        $rows[] = flexRow('🎫 クーポン', ($data['coupon_desc'] ?? 'クーポン') . '（' . $discountLabel . '）');
        if ($total !== null) {
            $rows[] = ['type' => 'separator', 'margin' => 'md'];
            $rows[] = flexRow('💰 予定金額', '¥' . number_format($total) . '〜');
        }
    } elseif ($price !== null) {
        $rows[] = ['type' => 'separator', 'margin' => 'md'];
        $rows[] = flexRow('💰 予定金額', '¥' . number_format($price) . '〜');
    }

    $rows[] = ['type' => 'separator', 'margin' => 'md'];
    $rows[] = ['type' => 'text', 'text' => 'こちらでよろしいですか？', 'size' => 'sm', 'color' => '#888888', 'margin' => 'md', 'wrap' => true];

    return [
        'type' => 'flex', 'altText' => 'ご予約内容の確認',
        'contents' => [
            'type' => 'bubble',
            'header' => [
                'type' => 'box', 'layout' => 'vertical', 'backgroundColor' => '#6B9E8A',
                'contents' => [['type' => 'text', 'text' => '📋 ご予約内容の確認', 'color' => '#ffffff', 'weight' => 'bold', 'size' => 'md']],
            ],
            'body' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => $rows,
            ],
            'footer' => [
                'type' => 'box', 'layout' => 'vertical',
                'contents' => [
                    ['type' => 'button', 'style' => 'primary', 'color' => '#6B9E8A',
                     'action' => ['type' => 'message', 'label' => '✅ 確定する', 'text' => '確定する']],
                    ['type' => 'button', 'style' => 'secondary', 'margin' => 'sm',
                     'action' => ['type' => 'message', 'label' => '✏️ 最初から選び直す', 'text' => '変更する']],
                    ['type' => 'button', 'style' => 'secondary', 'margin' => 'sm',
                     'action' => ['type' => 'message', 'label' => '◀ ひとつ前に戻る', 'text' => '戻る:クーポン']],
                ],
            ],
        ],
    ];
}

function flexRow(string $label, string $value): array
{
    return [
        'type' => 'box', 'layout' => 'horizontal', 'margin' => 'md',
        'contents' => [
            ['type' => 'text', 'text' => $label, 'size' => 'sm', 'color' => '#555555', 'flex' => 2],
            ['type' => 'text', 'text' => $value,  'size' => 'sm', 'weight' => 'bold',  'flex' => 3, 'wrap' => true],
        ],
    ];
}
