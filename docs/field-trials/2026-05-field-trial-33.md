# Field Trial 33 — 監査ログ（Audit Trail）

**Date:** 2026-05-27
**Theme:** `AuditLogger` — append-only 監査ログヘルパー
**Source:** NENE2 FT59 (auditlog), FT74 (auditlog), FT114

---

## What was done

- `class/xion/AuditLogger.php` — `record()` メソッドのみの write-only ヘルパー
- `tests/Unit/Xion/AuditLoggerTest.php` — 9 テスト、23 アサーション
- `docs/development/audit-log.md` — howto doc

---

## Findings

### F-1 — 監査失敗が主操作を止めてはいけない（設計方針）

`record()` が `PDOException` をキャッチして Monolog にエラーを記録する。
`prepare()` と `execute()` の両方を単一の try/catch で囲み、監査ログの書き込み失敗でユーザーの操作が中断されることを防ぐ。

### F-2 — 監査レコードは immutable（設計方針）

`AuditLogger` は `record()` のみ。UPDATE/DELETE API は意図的に持たない。

### F-3 — ペイロードに機密情報を含めない（セキュリティ）

パスワードハッシュ、トークン、API キーは payload に含めない。howto doc に明記。

### F-4 — Logger 注入によるテスト容易性

`Log::getInstance()` はランタイム定数（`ACCESS_LOG_PATH` 等）を必要とするため、テスト環境での直接呼び出しが困難だった。コンストラクタに `?Logger $logger = null` を追加し、デフォルトで `Log::getInstance()` を使いつつテストでは no-handler の `Logger('test')` を注入するパターンで解決した。

---

## Patterns Validated

- `INSERT INTO audit_log (actor_id, action, ...) VALUES (:actor_id, ...)` パターン
- `JSON_UNESCAPED_UNICODE` による日本語 payload の安全エンコード
- `PDOException` キャッチで主操作を保護（prepare / execute 両方を単一 try/catch）
- before/after スナップショットパターン（howto doc で文書化）
- コンストラクタ注入によるテスト容易性（PDO + Logger）
