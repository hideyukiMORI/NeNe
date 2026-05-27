# Field Trial 30 — JWT 認証実装

**Date:** 2026-05-27
**Theme:** `JwtCodec` — 外部ライブラリなし HS256 JWT 発行・検証
**Source:** NENE2 FT110 (jwtlog), FT113 (JWT refresh token rotation)

---

## What was done

- `class/xion/JwtCodec.php`
  - `issue(array $claims, ?int $ttl): string`
  - `decode(string $token): ?array` — 失敗時 null（throw なし）
  - `require(): array` — Authorization: Bearer ヘッダー読み取り + 検証、失敗時 HttpTermination(401)
  - alg:none 防御、exp 必須、nbf 検証、hash_equals 署名比較
- `config/error_codes.php` — `JWT-INVALID` (401) 追加
- `docs/development/error-codes.md` — `JWT-INVALID` カタログ行追加
- `tests/Unit/Xion/JwtCodecTest.php` — 12 テスト
- `docs/development/agent-bearer-auth.md` — JwtCodec セクション追加

---

## Findings

### F-1 — `exp` 必須にすることで無期限トークンを防ぐ（設計）

`decode()` で `exp` がない/`int` でないトークンは null を返す。
NENE2 FT110 F-4 と同様、`exp` なしトークンは永久に有効になるリスクがある。

### F-2 — 外部ライブラリ不要（NeNe 設計原則確認）

HS256 = HMAC-SHA256 + base64url。PHP 標準関数だけで実装でき、依存を増やさない。

### F-3 — `error-codes.md` 同期テストが新規エラーコード追加を捕捉

`ErrorCodeTest::testEveryRuntimeCodeAppearsInDocsMarkdownTable()` が `config/error_codes.php` と `docs/development/error-codes.md` の乖離を CI で検出する。`JWT-INVALID` 追加時に両ファイルの更新が必要であることを確認した。

---

## Patterns Validated

- `hash_equals()` による定数時間署名比較（タイミング攻撃防止）
- alg フィールド検証（`HS256` のみ受け入れ）
- `exp` + `nbf` のタイムスタンプ検証
- `issue()` の `iat`/`exp` 自動付与
- `base64_decode(..., strict: true)` で不正な base64 を早期リジェクト
