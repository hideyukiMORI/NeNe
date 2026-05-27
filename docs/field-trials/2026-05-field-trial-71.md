# Field Trial 71 — Search History

**Date**: 2026-05-27
**Branch**: `feat/ft71-search-history`
**Baseline**: post FT70 merge

## Goal

Establish a per-user search history pattern for NeNe applications. Provide deduplication (upsert on re-push), auto-trimming to a configurable limit, and a simple recent-queries API.

## What was built

### `Nene\Xion\SearchHistory` (`class/xion/SearchHistory.php`)

DB-backed search history providing:

| Method | Description |
| --- | --- |
| `push(string $userId, string $query): void` | Upsert query, trim to max limit. |
| `recent(string $userId, int $limit = 10): array` | Recent queries, newest first. |
| `clear(string $userId): int` | Delete all entries; returns count. |

Key design points:

- **UNIQUE (user_id, query)**: deduplication at DB layer.
- **Upsert**: `INSERT OR REPLACE` (SQLite) / `ON DUPLICATE KEY UPDATE` (MySQL) — re-push moves query to top.
- **Auto-trim**: after each push, oldest entries beyond `$maxEntries` are deleted.
- **Whitespace trim + empty guard**: query is trimmed; empty throws `InvalidArgumentException`.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/SearchHistoryTest.php`)

14 unit tests covering:

- push adds query
- push deduplicates
- push duplicate moves to top
- push trims older than max entries
- push empty query throws
- push whitespace-only query throws
- push trims query whitespace
- recent returns newest first
- recent returns empty for new user
- recent is user-isolated
- recent limit is applied
- clear removes all entries
- clear returns count of removed entries
- clear is user-isolated

### Howto (`docs/development/search-history.md`)

Covers: schema, API table, usage, deduplication behavior, auto-trim, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`SearchHistory` is a clean `Nene\Xion` helper. 14 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
