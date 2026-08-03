# HAPTIC 関連 cron（さくらインターネット / mogans）

サーバの `crontab -l` から HAPTIC 分のみ抜粋。
（PCリカバリ後の復旧用メモ。実体はサーバ側 crontab が正）

## 1. セッション/リマインド/誕生日クーポン/物販リマインド

```
# NAME: HAPTIC LINE予約 cron（セッション/リマインド/誕生日クーポン/物販）
*/5 * * * * /usr/local/bin/curl -s "https://haptic.irodori.tokyo/webhook/webhook.php?cron=irodori_cron_2024" >> /home/mogans/log/haptic_cron.log 2>&1
```

5分ごとに `webhook/webhook.php` の `handleCronTimeout()` を実行する。

- 予約フロー放置セッションの 10分リマインド / 15分リセット
- `reminders` テーブルの前日18:15リマインド送信
- 誕生日クーポン発行（`coupon_templates` の `coupon_type='birthday'` に連動）＋QR付きFlex配信
- `product_sales.remind_months` 経過分の物販リマインド

## 2. シナリオ配信（来店後◎ヶ月／購入後◎ヶ月）

```
# NAME: HAPTIC LINE予約 シナリオ配信（来店後/購入後◎ヶ月）
15 18 * * * /usr/local/bin/curl -s "https://haptic.irodori.tokyo/webhook/webhook.php?cron=irodori_cron_2024&job=scenario" >> /home/mogans/log/haptic_scenario_cron.log 2>&1
```

毎日18:15に `handleScenarioBatch()` を実行。`line_scenarios`（`is_active=1`）を対象に
`line_scenario_logs` で重複送信を防ぎつつ配信する。
深夜配信を避けるため、5分おきの通常cronとは分離してある。

## 注意

- `cron=` の値は `.env` の `CRON_SECRET`（未設定時のフォールバックが `irodori_cron_2024`）と一致する必要がある。
- ログは `/home/mogans/log/haptic_cron.log` / `haptic_scenario_cron.log`。
- 自動デプロイ（git pull）cron は HAPTIC には設定されていない。デプロイは手動。
