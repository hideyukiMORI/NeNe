# Field Trial 28 — Rate Limiting 実装

**Date:** 2026-05-27
**Theme:** `RateLimiter` + `RateLimiterStorageInterface` + `RedisRateLimiterStorage` 実装
**Source:** NENE2 FT46 (ratelimitlog), FT73 (quotalog), FT107 (throttlelog)

---

## What was done

- `class/func/RateLimiterStorageInterface.php` — storage 抽象インターフェース
- `class/func/RedisRateLimiterStorage.php` — Predis 実装（INCR + EXPIRE）
- `class/func/RateLimiter.php` — 固定ウィンドウレートリミッター
  - `check()`: 超過時 HttpTermination(429) + Retry-After ヘッダー
  - `remaining()`: 残りリクエスト数
- `config/error_codes.php` — `RATE-LIMIT-EXCEEDED` (429) 追加
- `docs/development/error-codes.md` — `RATE-LIMIT-EXCEEDED` カタログ行追加（ErrorCodeTest の contract test を通すため）
- `tests/Unit/Func/RateLimiterTest.php` — 11 テスト（インメモリストレージ使用）
- `docs/development/rate-limiting.md` — 実際の API に合わせて全面更新

---

## Findings

### F-1 — storage interface でテスタビリティを確保（設計）

NENE2 FT107 の `InMemoryRateLimitStorage` と同様に、`RateLimiterStorageInterface` を抽出してテストを Redis 依存にしない。テストファイル内に `ArrayRateLimiterStorage` を `final class` として定義することで、production autoload に漏れ込まない。

### F-2 — X-RateLimit-* ヘッダーは標準的なクライアント期待値（best practice）

`X-RateLimit-Limit` / `X-RateLimit-Remaining` / `X-RateLimit-Reset` を毎回設定することで、クライアントが 429 になる前に上限を把握できる。`check()` が毎回これらを設定するため、呼び出し側での手動ヘッダー管理は不要。

### F-3 — リバースプロキシ背後では REMOTE_ADDR が信頼できない（note）

本番では `X-Forwarded-For` や `X-Real-IP` を使うが、プロキシが制御下にある場合のみ信頼する。`docs/development/rate-limiting.md` の key strategies セクションにすでに `$ip` 変数として抽象化されており、呼び出し側で適切な IP 取得ロジックを差し込める設計になっている。

### F-4 — `headers_list()` は CLI SAPI では空を返す（テスト上の制約）

`@runInSeparateProcess` は CLI SAPI での `header()` 呼び出し結果を `headers_list()` に反映しない。ヘッダー設定の網羅テストは不可能なため、「check() が例外なく完了し、storage の count が期待値になること」でヘッダーパスのコードが実行されたことを間接的に確認する設計に変更した。

### F-5 — `ErrorCodeTest` の contract test が error_codes.php と error-codes.md の同期を強制

新しいエラーコードを `config/error_codes.php` に追加するだけでは不十分で、`docs/development/error-codes.md` のカタログテーブルへの追加も必須であることが判明した（`tests/Unit/Xion/ErrorCodeTest::testEveryRuntimeCodeAppearsInDocsMarkdownTable` が失敗）。エラーコード追加時の手順として明示化しておく。
