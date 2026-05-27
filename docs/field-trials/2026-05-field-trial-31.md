# Field Trial 31 — RBAC (Role-Based Access Control)

**Date:** 2026-05-27
**Theme:** `RoleGuard` クラス — JWT クレームベースの RBAC ヘルパー
**Source:** NENE2 FT111 (rbaclog)

---

## What was done

- `class/xion/RoleGuard.php` — `require()`, `requireAny()`, `has()` + 403 ガード
- `config/error_codes.php` — `FORBIDDEN` (403) 追加
- `docs/development/error-codes.md` — `FORBIDDEN` をカタログ表に追記
- `tests/Unit/Xion/RoleGuardTest.php` — 11 テスト
- `docs/development/rbac.md` — howto doc（新規）

---

## Findings

### F-1 — 401 vs 403 の区別が重要（NENE2 FT111 F-3 と同様）

- 未認証/トークン無効 → 401 (`JwtCodec::require()`)
- 認証済みだがロール不足 → 403 (`RoleGuard::require()`)

### F-2 — JWT クレームにロールを含めるトレードオフ

JWT ベースのロール: ロール変更はトークン期限まで反映されない。
DB 毎回確認: 常に最新だが追加クエリが必要。
NeNe のデフォルトは JWT クレーム方式（シンプル）。高セキュリティ要件の場合はドキュメントに従い DB 確認を追加。

### F-3 — error_codes.php と error-codes.md の同期テスト

`ErrorCodeTest::testEveryRuntimeCodeAppearsInDocsMarkdownTable()` により、
`config/error_codes.php` に追加したコードは必ず `docs/development/error-codes.md` にも追記しなければならない。
`FORBIDDEN` をどちらにも追加することで CI が通過する。

---

## Patterns Validated

- `RoleGuard::require()` → 403 HttpTermination
- `RoleGuard::requireAny()` → 複数ロールの OR 条件
- `RoleGuard::has()` → 非スロー確認
