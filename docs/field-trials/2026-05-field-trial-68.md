# Field Trial 68 — Tag / Label System

**Date**: 2026-05-27
**Branch**: `feat/ft68-tag-manager`
**Baseline**: post FT67 merge

## Goal

Establish a generic M:N tag/label pattern for NeNe applications. Provide an entity-type-agnostic helper for attaching, detaching, and querying tags across any entity type.

## What was built

### `Nene\Xion\TagManager` (`class/xion/TagManager.php`)

Two-table M:N tag helper providing:

| Method | Description |
| --- | --- |
| `attach(string $entityType, string $entityId, array $tagNames): void` | Attach tags; creates if absent; idempotent. |
| `detach(string $entityType, string $entityId, string $tagName): bool` | Remove one tag attachment. |
| `syncTags(string $entityType, string $entityId, array $tagNames): void` | Atomically replace all tags. |
| `tagsFor(string $entityType, string $entityId): array` | Tag names (alphabetical). |
| `entitiesWithTag(string $tagName, ?string $entityType = null): array` | Entity IDs with a given tag. |

Key design points:

- **Tag reuse**: single `tags` row per name shared across entity types.
- **Idempotent attach**: `INSERT OR IGNORE` / `INSERT IGNORE` on both tables.
- **`syncTags()` atomicity**: DELETE all + INSERT new in a transaction.
- **`(entity_type, entity_id)`**: entity-agnostic design — one helper for any model.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/TagManagerTest.php`)

17 unit tests covering:

- attach creates tags and returns them for entity
- attach is idempotent
- attach reuses existing tag row
- attach empty array does nothing
- detach returns true when tag was attached
- detach returns false when tag was not attached
- detach removes tag
- syncTags replaces existing tags
- syncTags with empty array removes all tags
- syncTags only affects target entity
- tagsFor returns alphabetically sorted
- tagsFor returns empty for untagged entity
- tagsFor is entity-type-isolated
- entitiesWithTag returns entity IDs
- entitiesWithTag filters by entity type
- entitiesWithTag without type filter returns all
- entitiesWithTag returns empty for unused tag

### Howto (`docs/development/tag-manager.md`)

Covers: schema, API table, usage examples, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`TagManager` is a clean `Nene\Xion` helper. 17 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
