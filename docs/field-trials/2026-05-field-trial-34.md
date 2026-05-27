# Field Trial 34 — Webhook 署名 (HMAC-SHA256)

**Date:** 2026-05-27
**Theme:** `WebhookSigner` — Stripe 形式 HMAC-SHA256 Webhook 署名・検証
**Source:** NENE2 FT104 (hmaclog), FT120 (webhookdeliverylog)

---

## What was done

- `class/xion/WebhookSigner.php`
  - `sign()`: `t=<ts>,v1=<hmac>` ヘッダー値生成
  - `verify()`: タイムスタンプ窓 + HMAC 検証（hash_equals）
  - `generateSecret()`: 256ビットランダムシークレット生成
- `config/error_codes.php` — `WEBHOOK-SIGNATURE-INVALID` (401) 追加
- `tests/Unit/Xion/WebhookSignerTest.php` — 13 テスト
- `docs/development/webhook-signing.md` — howto doc

---

## Findings

### F-1 — `hash_equals()` vs `===` はテストで検出できない（最重要）

NENE2 FT104 F-1 と同様。`===` で書いてもテストは通る。`WebhookSigner` が `hash_equals()` を使うことで、実装者がデフォルトで安全な比較を得られる。

### F-2 — タイムスタンプを署名対象に含めることでリプレイ攻撃を防ぐ

`"{timestamp}.{rawBody}"` を署名することで、タイムスタンプを変更するとシグネチャが無効になる（Stripe の設計と同じ）。

---

## Patterns Validated

- `t=<ts>,v1=<hex>` ヘッダーフォーマット（parse / generate）
- `hash_equals()` による定数時間比較
- `abs($now - $timestamp) > $tolerance` によるリプレイウィンドウチェック
- `bin2hex(random_bytes(32))` シークレット生成
