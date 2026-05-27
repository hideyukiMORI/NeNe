# Field Trial 25 — Cursor-based Pagination

**Date:** 2026-05-27
**Theme:** Keyset (cursor-based) pagination — `Cursor` / `CursorPage` クラスと `DataMapperBase::findPage()` の追加
**Source:** NENE2 FT42 (cursorlog), FT63, FT100

---

## What was done

- `class/xion/Cursor.php` — base64url カーソルトークンの encode/decode value object
- `class/xion/CursorPage.php` — ページ結果 value object（items / has_more / next_cursor）
- `DataMapperBase::findPage(?string $cursor, int $limit): CursorPage` — keyset WHERE 句による DB クエリ
- `tests/Unit/Xion/CursorTest.php` — 6 テスト
- `tests/Unit/Xion/CursorPageTest.php` — 3 テスト
- `docs/development/cursor-pagination.md` — howto doc

---

## Findings

### F-1 — OFFSET は深いページで性能劣化する（情報）

NENE2 FT100 の 500 行シードベンチマークでは、OFFSET 490 は cursor 方式の約 3× 遅い。
`findALL()` は少量データには十分だが、一覧 API は最初から `findPage()` を使う習慣を付ける。

**Action:** `docs/development/cursor-pagination.md` に OFFSET との比較を記載。

### F-2 — `limit + 1` プローブパターンが has_more の最小コスト実装（パターン確立）

`COUNT(*)` を使わず `limit + 1` 件取得して最後の1件を捨てる。
総件数は返さない（cursor API の慣例）。

---

## Patterns Validated

- `(created_at < :ca OR (created_at = :ca AND id < :id))` keyset WHERE
- `strtr(base64_encode(...), '+/', '-_')` base64url エンコード
- `array_pop()` でプローブ行を除去
- `Cursor::decode()` が `null` を返すことで不正トークンを安全に扱える
