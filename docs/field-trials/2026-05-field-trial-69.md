# Field Trial 69 — Comment Thread

**Date**: 2026-05-27
**Branch**: `feat/ft69-comment-thread`
**Baseline**: post FT68 merge

## Goal

Establish a threaded comment pattern for NeNe applications. Support top-level posting, nested replies with depth enforcement, and soft deletion that preserves thread structure.

## What was built

### `Nene\Xion\CommentThread` (`class/xion/CommentThread.php`)

DB-backed threaded comment manager providing:

| Method | Description |
| --- | --- |
| `post(string $entityType, string $entityId, string $authorId, string $body): int` | Top-level comment. |
| `reply(int $parentId, string $authorId, string $body): int` | Nested reply with depth check. |
| `softDelete(int $commentId, string $authorId): bool` | Author-enforced soft delete. |
| `list(string $entityType, string $entityId): array` | All comments ordered by id ASC. |

Key design points:

- **`depth` column**: stored on insert (parent.depth + 1); no tree traversal needed for rendering.
- **Depth limit**: default 5, configurable via constructor. Throws `InvalidArgumentException` when exceeded.
- **Soft delete semantics**: `body = '[deleted]'`, `author_id = NULL`, row retained for threading.
- **`(entity_type, entity_id)`** entity-agnostic design.
- **Empty body guard**: throws for empty/whitespace-only bodies.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

### Tests (`tests/Unit/Xion/CommentThreadTest.php`)

20 unit tests covering:

- post returns id
- posted comment appears in list
- posted comment has depth zero
- post empty body throws
- reply returns id
- reply has depth one more than parent
- reply has parent_id set
- reply to nonexistent parent throws
- reply empty body throws
- reply max depth enforced
- softDelete returns true by author
- softDelete returns false by non-author
- softDelete replaces body
- softDelete nulls author_id
- softDelete sets deleted_at
- softDelete already deleted returns false
- list returns all comments including deleted
- list is entity-type-isolated
- list is entity-id-isolated
- list orders by id ascending

### Howto (`docs/development/comment-thread.md`)

Covers: schema, API table, usage, soft delete behavior, depth limit configuration, key design points, test patterns.

## Findings

### F-1 — No finding (clean trial)

`CommentThread` is a clean `Nene\Xion` helper. 20 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
