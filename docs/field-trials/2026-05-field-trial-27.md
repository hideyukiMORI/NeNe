# Field Trial 27 — Optimistic Locking / ETag 実装

**Date:** 2026-05-27
**Theme:** `OptimisticLock` クラス追加 — If-Match / ETag HTTP 条件付き書き込みサポート
**Source:** NENE2 FT39 (locklog), FT54 (optimisticlog), FT56 (etaglog), FT105, FT106

---

## What was done

- `class/xion/OptimisticLock.php` — ETag / If-Match HTTP helper
  - `parseIfMatch()`, `etagFor()`, `sendETag()`, `requireVersion()`, `conflict()`
- `config/error_codes.php` — `PRECONDITION-REQUIRED` (428), `PRECONDITION-FAILED` (412), `CONFLICT` (409)
- `tests/Unit/Xion/OptimisticLockTest.php` — 13 テスト
- `docs/development/optimistic-locking.md` — フレームワークヘルパーセクションを先頭に追加

---

## Findings

### F-1 — If-Match の書式ゆらぎ（low）

`"v5"` / `"5"` / `v5` / `5` の4形式を許容する。
RFC 9110 は強い ETag を `"..."` で囲む形式で定義するが、クライアントが引用符を落とすケースがある。
`parseIfMatch()` は全形式を受け入れて正規化する。

### F-2 — version = 0 または負数を拒否（low）

`version` は 1 始まりであるため、0 以下は `null` として扱い 428 に集約する。

### F-3 — HttpResponse.json() は存在しない（low）

`HttpResponse` には `json()` ファクトリメソッドがない。
`JsonResponder::response(array $data, int $statusCode)` を使用して JSON レスポンスを構築する。

---

## Patterns Validated

- `header('ETag: "vN"')` による ETag 送信
- `$_SERVER['HTTP_IF_MATCH']` による If-Match 取得
- `HttpTermination` を使った 412 / 428 早期終了
- `WHERE id = :id AND version = :version` + `rowCount() > 0` による競合検出
