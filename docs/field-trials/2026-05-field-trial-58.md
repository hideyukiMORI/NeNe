# Field Trial 58 — Notification Inbox

**Date**: 2026-05-27
**Branch**: `feat/ft58-notification-inbox`
**Baseline**: `b71f4b6` (post FT51–FT53 merge)
**NENE2 reference**: FT130 (notificationlog)

## Goal

Establish a user notification inbox pattern for NeNe: push delivery, list with unread filtering, single and bulk mark-as-read, and unread count for badge display.

## What was built

### `Nene\Xion\NotificationInbox` (`class/xion/NotificationInbox.php`)

| Method | Returns | Description |
|--------|---------|-------------|
| `push(userId, type, title, body)` | `int` | Insert notification. Returns row ID. |
| `list(userId, unreadOnly=false)` | `array[]` | Newest-first (ORDER BY id DESC). |
| `markRead(notificationId, userId)` | `bool` | Mark one read. Idempotent; owner-enforced. |
| `markAllRead(userId)` | `int` | Mark all unread. Returns count marked. |
| `unreadCount(userId)` | `int` | Count unread (for badge). |

Key design points:

- **`read_at` nullable timestamp**: captures *when* the notification was read; `WHERE read_at IS NULL` is indexable. Avoids a separate `updated_at` column for the read event.
- **`ORDER BY id DESC`**: stable sort for same-second inserts; `created_at DESC` has ambiguous order when multiple rows share the same timestamp.
- **`markRead()` idempotency**: checks `read_at` before UPDATE — re-calling preserves the original timestamp.
- **`markAllRead()` idempotency**: `WHERE read_at IS NULL` — already-read rows untouched.
- **Cross-user 404 not 403**: `markRead()` returns `false` for wrong-user access to prevent notification ID enumeration.
- **`read` convenience field**: list items include `'read' => $r['read_at'] !== null` so clients don't need to check `read_at`.
- **`unreadCount` in list response (recommended)**: returning the count alongside the list saves the client an extra round-trip for badge updates.

### Tests (`tests/Unit/Xion/NotificationInboxTest.php`)

19 unit tests covering:

- push: returns ID, unread by default, with body, int userId, distinct IDs
- list: newest-first, owner isolation, empty for new user, unreadOnly filter, field presence
- markRead: returns true + sets read_at, idempotent (preserves original read_at), wrong user (false), not found (false)
- markAllRead: returns count, idempotent (returns 0 second time), does not affect other users
- unreadCount: correct count, zero for new user, decreases after markAllRead

### Howto (`docs/development/notification-inbox.md`)

`read_at` vs `is_read` comparison, API table, basic usage, response shape, `ORDER BY id DESC` rationale, idempotency, cross-user access policy, pagination note with cursor reference.

## Findings

### F-1 — Pagination is a future concern (deferred)

The current implementation returns all notifications for a user. For large inboxes, cursor-based pagination (`id < :cursor`) is needed before production. Deferred — the `Cursor` + `CursorPage` helpers (FT25) provide the building blocks.

**Re-evaluation trigger**: a trial or real app surfaces an inbox with >100 notifications as a performance issue.

### F-2 — No other framework findings

All 19 tests pass; CS Fixer and Phan clean.

## Decision

Merge as-is. F-1 deferred per methodology (document now, implement when triggered). No follow-up Issues raised.
