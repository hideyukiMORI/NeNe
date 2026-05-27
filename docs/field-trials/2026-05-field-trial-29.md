# Field Trial 29 — State Machine 実装

**Date:** 2026-05-27
**Theme:** `WorkflowDefinition` クラス実装 — コードドリブンな遷移マップと 409 ガード

---

## What was done

- `class/func/WorkflowDefinition.php`
  - `isValidWorkflow()`, `initialState()`, `allowedNext()`, `canTransition()`, `assertTransition()`, `allStates()`
  - 組み込みワークフロー: `order`, `content`
- `config/error_codes.php` — `INVALID-TRANSITION` (409) 追加
- `docs/development/error-codes.md` — `INVALID-TRANSITION` をカタログテーブルに追加
- `tests/Unit/Func/WorkflowDefinitionTest.php` — 16 テスト
- `docs/development/state-machines.md` — フレームワークヘルパーセクション追加

---

## Findings

### F-1 — PHP enum vs static map の選択（設計）

NENE2 は PHP backed enum を使う。NeNe の既存 doc は static map を使う。
両者の差: enum は型安全だが各ワークフローに enum クラスが必要; static map は1ファイルで管理できる。
NeNe では複数ワークフローを1ファイルで管理できる static map を選択。アプリ開発者が enum を好む場合は enum も使える（排他ではない）。

### F-2 — terminal state の `assertTransition` が 409（設計確認）

terminal state からの遷移は `allowedNext() = []` のため、いかなる `toState` も 409 になる。正しい動作。

### F-3 — error-codes.md の同期テスト（検知）

`config/error_codes.php` に追加したコードが `docs/development/error-codes.md` のテーブルに未記載だと `ErrorCodeTest::testEveryRuntimeCodeAppearsInDocsMarkdownTable` が失敗する。エラーコード追加時は必ずドキュメントも更新する。

---

## Patterns Validated

- `match` 代わりの連想配列で遷移マップを表現
- terminal state = 空リスト
- `assertTransition()` が `HttpTermination(409)` を throw するため、controller は if 文不要
- `allowed_next` をレスポンスに含めることでクライアントが次のアクションを知れる

---

## Results

| Check | Result |
|---|---|
| PHPUnit (unit) — WorkflowDefinitionTest | 16 tests, 16 assertions — OK |
| PHPUnit (unit) — full suite | 254 tests — OK |
| Phan | 0 errors (exit 0) |

---

## Related

- `class/func/WorkflowDefinition.php` — 実装
- `docs/development/state-machines.md` — 設計ドキュメント
- `config/error_codes.php` — `INVALID-TRANSITION` エントリ
