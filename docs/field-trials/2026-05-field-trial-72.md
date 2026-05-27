# Field Trial 72 — Bookmark / Saved Items

**Date**: 2026-05-27
**Branch**: `feat/ft72-bookmark`
**Baseline**: post FT71 merge

## Goal

Establish a generic bookmark / saved-item pattern for NeNe applications. Provide a user-agnostic, entity-type-agnostic helper for wishlists, favourites, and "read later" lists.

## What was built

### `Nene\Xion\Bookmark` (`class/xion/Bookmark.php`)

DB-backed per-user bookmark manager providing:

| Method | Description |
| --- | --- |
| `save(string $userId, string $entityType, string $entityId, string $collection = ''): bool` | Idempotent save; true if new. |
| `remove(string $userId, string $entityType, string $entityId): bool` | Remove; true if deleted. |
| `isSaved(string $userId, string $entityType, string $entityId): bool` | Status check. |
| `list(string $userId, ?string $entityType = null, ?string $collection = null): array` | Filtered list, newest first. |

Key design points:

- **UNIQUE (user_id, entity_type, entity_id)**: DB-enforced deduplication.
- **`save()` idempotent**: `INSERT OR IGNORE` / `INSERT IGNORE` — duplicate returns `false`.
- **`collection`**: optional grouping field (default `''`); enables wishlist/favourites/read-later etc.
- **`(entity_type, entity_id)` agnostic**: reusable across all content types.
- **`list()` filters**: type and/or collection are optional; all nullable.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/BookmarkTest.php`)

16 unit tests covering:

- save returns true on first bookmark
- save returns false when already saved
- save with collection
- remove returns true when bookmarked
- remove returns false when not bookmarked
- remove deletes bookmark
- isSaved returns true after save
- isSaved returns false before save
- isSaved is user-isolated
- isSaved is entity-type-isolated
- list returns all bookmarks for user
- list filters by entity type
- list filters by collection
- list is user-isolated
- list returns empty for new user
- list result contains expected keys

### Howto (`docs/development/bookmark.md`)

Covers: schema, API table, usage, collections, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`Bookmark` is a clean `Nene\Xion` helper. 16 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
