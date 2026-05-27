# Field Trial 35 — フィーチャーフラグ実装

**Date:** 2026-05-27
**Theme:** `FeatureFlagService` — DB バックエンドのフィーチャーフラグ評価
**Source:** NENE2 FT71, FT121 (featureflaglog)

---

## What was done

- `class/func/FeatureFlagService.php`
  - `isEnabled(string $flagName, ?int $userId): bool`
  - 優先度チェーン: ユーザーオーバーライド → グローバル → rollout_pct → false
- `tests/Unit/Func/FeatureFlagServiceTest.php` — 11 テスト (SQLite :memory: DB)
- `docs/development/feature-flags.md` — `FeatureFlagService` セクション追加 + スキーマ更新

---

## Findings

### F-1 — rollout_pct の決定論的バケット（NENE2 FT121 と同じ設計）

`abs(crc32($userId . '.' . $flagName)) % 100` により、同じユーザー・フラグ組み合わせが常に同じバケットに入る。rollout を 10% → 20% → 100% と増やすとき、以前の対象ユーザーが常に含まれる。

### F-2 — ユーザーオーバーライドが kill switch としても機能する（重要）

`is_enabled=0` のユーザーオーバーライドはグローバルが有効でも無効化する。特定ユーザーの問題対応に使える。

---

## Patterns Validated

- 3 段階優先度チェーン
- `abs(crc32($userId . '.' . $flagName)) % 100` rollout bucket
- Constructor injection による PDO テスタビリティ
- SQLite `:memory:` を使ったテスト戦略（Mock PDO の多段 prepare チェーンを回避）
