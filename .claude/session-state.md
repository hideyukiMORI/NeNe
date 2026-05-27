# Session State — 2026-05-28

## Current Branch
`main` (PR #713 pending CI + auto-merge)

## Pending PRs
- **PR #713** (`chore/dx-improvements` → `main`) — CI 待ち、auto-merge (squash) 有効
  - Contains: tools/make-xion.php, tools/xion-index.php, tools/ft-done.php,
    composer ft:done / make:xion / xion:index / precommit, CLAUDE.md updates,
    class/xion/INDEX.md (259 classes all categorized)

## All DX tools landing in main when #713 merges

| Command | 効果 |
|---|---|
| `composer make:xion -- Foo` | class/xion/Foo.php + tests/Unit/Xion/FooTest.php + INDEX登録 |
| `composer xion:index` | INDEX.md を PHPDoc から再生成（空Uncategorized自動削除） |
| `composer ft:done -- FT265 Foo "desc" 712` | 3ファイル一括更新 |
| `composer precommit` | format → analyze → test |
| `composer analyze:file -- a.php b.php` | 絞りこみ Phan (~14s) |
| `CLAUDE.md` | AI クイックリファレンス（repo root） |
| `.phan/suppress-cheatsheet.md` | Phan 抑制コメント早見表 |
| `docs/ai/self-review/xion-class.md` | 新 Xion クラス PR チェックリスト |
| `class/xion/DbUpsert.php` | cross-driver upsert ヘルパー |

## Nothing uncommitted
All work committed and pushed.

## Next session starting point
- Latest FT wave: FT255–FT264 (10th wave)
- Ready for FT265+
- After #713 merges: all DX tooling is in main
# CI trigger Thu May 28 02:55:17 JST 2026
