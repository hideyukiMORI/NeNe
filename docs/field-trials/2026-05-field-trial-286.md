# Field Trial 286 — PinnedItem

**Date**: 2026-05-29
**Branch**: `feat/ft286-pinned-item`
**Baseline**: post FT285 merge

## Goal

Add `Nene\Xion\PinnedItem` — an ordered set of pinned items per context (pinned posts/messages, featured products). Distinct from `Bookmark` (FT72, private) and `Watchlist` (FT80): a shared, ordered, curated list.

## What was built

### `Nene\Xion\PinnedItem` (`class/xion/PinnedItem.php`)

| Method | Description |
| --- | --- |
| `pin(context, item, pinnedBy=0)` | Append (idempotent; keeps position on re-pin). |
| `unpin(context, item) / isPinned(...)` | Remove / check. |
| `items(context)` | Pinned items in order. |
| `moveToTop / moveToBottom (context, item)` | Reorder. |
| `count(context) / clear(context): int` | Size / wipe. |

Key design points:

- **Append-on-pin**: new pins get `MAX(position)+1`; **idempotent re-pin keeps position** (no jump back to the end).
- **Reorder primitives** `moveToTop` (`MIN-1`) / `moveToBottom` (`MAX+1`) avoid full renumbering.
- **PDO injection**; UNIQUE (context, item).

### Tests (`tests/Unit/Xion/PinnedItemTest.php`)

12 unit tests (15 assertions): pin append order, idempotent pin, isPinned, unpin (+ missing no-op), moveToTop, moveToBottom, **idempotent re-pin keeps reordered position**, context separation, clear, validation (empty context/item).

## Findings

### F-1 — No finding (clean trial)

Clean `Nene\Xion` helper. 12 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. No follow-up Issues raised.
