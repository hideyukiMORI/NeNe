# Field Trial 26 — Soft Delete (Logical Deletion)

**Date:** 2026-05-27
**Theme:** `DataMapperBase` に soft delete を実装 — `SOFT_DELETE` 定数 + 5 メソッド追加
**Source:** NENE2 FT35 (softlog), FT49 (softdelete), FT62 (softdeletelog), FT108

---

## What was done

- `ini/xSystemIni.php` — `DB_COLUMN_NAME_DELETED = 'deleted_at'` 定数追加
- `DataMapperBase::SOFT_DELETE = false` — opt-in 定数
- `DataMapperBase::delete()` — SOFT_DELETE=true 時に UPDATE SET deleted_at=NOW()
- `DataMapperBase::find()` / `findALL()` / `countAll()` — deleted_at IS NULL フィルタ追加（SOFT_DELETE=true 時のみ）
- 新規メソッド: `softDelete()`, `restore()`, `findTrashed()`, `purge()`, `purgeAll()`
- `tests/Unit/Xion/SoftDeleteMapperTest.php` — 17 テスト（222 → 222+17 全通過）
- `docs/development/soft-delete.md` — howto doc

---

## Findings

### F-1 — `delete()` の soft delete ブランチが未実装だった（medium）

`DB_IS_PHYSICAL_DELETE = false` のとき `delete()` は何もしない（no-op）だった。
FT26 でこれを `SOFT_DELETE = true` mapper に対して `UPDATE SET deleted_at = NOW()` に完成させた。

### F-2 — デフォルト除外パターンが重要（security note）

NENE2 FT108 F-1 と同様: `WHERE deleted_at IS NULL` を書き忘れると削除済みデータが漏洩する。
`SOFT_DELETE = true` にすることで `find()` / `findALL()` が自動的に除外するため、書き忘れリスクが排除される。

---

## Patterns Validated

- opt-in `protected const SOFT_DELETE = false` パターン
- `WHERE deleted_at IS NULL` の自動付与（find/findALL/findPage/countAll）
- `purge()` のガード: `AND deleted_at IS NOT NULL` で active rows の物理削除を防ぐ
- `restore()` / `purge()` の `rowCount() > 0` による bool 返却パターン
