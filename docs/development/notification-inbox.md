# Notification Inbox

`Nene\Xion\NotificationInbox` provides a user notification inbox: push delivery, list with unread filtering, single and bulk mark-as-read, and unread count for badge display.

## Schema

```sql
CREATE TABLE notifications (
    id         INTEGER      PRIMARY KEY AUTOINCREMENT,
    user_id    VARCHAR(255) NOT NULL,
    type       VARCHAR(64)  NOT NULL,
    title      VARCHAR(255) NOT NULL,
    body       TEXT         NOT NULL DEFAULT '',
    read_at    DATETIME     DEFAULT NULL,   -- NULL = unread
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_notifications_user ON notifications (user_id, id);
```

## `read_at` vs `is_read`

Use a nullable `read_at` timestamp instead of a boolean `is_read` column:

| Pattern | Advantage |
|---------|-----------|
| `read_at DATETIME NULL` | Preserves *when* read. `WHERE read_at IS NULL` is indexable. |
| `is_read TINYINT(1)` | Simpler but loses the timestamp. Needs a separate `read_at` column if the time ever matters. |

## API

| Method | Returns | Description |
|--------|---------|-------------|
| `push(userId, type, title, body)` | `int` | Insert a notification. Returns new row ID. |
| `list(userId, unreadOnly)` | `array[]` | Notifications newest-first (`ORDER BY id DESC`). |
| `markRead(notificationId, userId)` | `bool` | Mark one as read. Idempotent; owner-enforced. |
| `markAllRead(userId)` | `int` | Mark all unread. Returns count of rows marked. |
| `unreadCount(userId)` | `int` | Count unread for badge display. |

## Basic usage

```php
use Nene\Xion\NotificationInbox;

$inbox = new NotificationInbox();

// Push from a service after a user action
$inbox->push($userId, 'order.shipped', 'Your order has shipped', 'Tracking: 1Z999...');

// List all (controller / REST response)
$items       = $inbox->list($userId);
$unreadCount = $inbox->unreadCount($userId);

// List unread only
$unread = $inbox->list($userId, unreadOnly: true);

// Mark one as read
if (!$inbox->markRead($notificationId, $userId)) {
    http_response_code(404); // not found or wrong user
    exit;
}

// Mark all as read
$markedCount = $inbox->markAllRead($userId);
```

## Response shape

Each item returned by `list()`:

```json
{
  "id": 42,
  "type": "order.shipped",
  "title": "Your order has shipped",
  "body": "Tracking: 1Z999...",
  "read": false,
  "read_at": null,
  "created_at": "2026-05-27 10:00:00"
}
```

Include `unread_count` in list responses to save the client an extra round-trip for badge updates.

## `ORDER BY id DESC` (not `created_at DESC`)

Sorting by `id` gives a stable order when multiple notifications are inserted within the same second. `created_at` has second-level precision in most DBs — same-second inserts would have indeterminate relative order with `ORDER BY created_at DESC`.

## Idempotency

- **`markRead()`**: checks `read_at` before updating. Re-calling preserves the original timestamp.
- **`markAllRead()`**: uses `WHERE read_at IS NULL` — already-read rows are untouched.

## Cross-user access

`markRead()` returns `false` for wrong-user access without throwing or returning a specific error status. Callers should return 404, not 403, to avoid confirming that a given notification ID exists for another user.

## Pagination

The current implementation returns all notifications. Add cursor-based pagination before production if the inbox can grow large:

```sql
-- cursor = last seen id
SELECT ... WHERE user_id = ? AND id < :cursor ORDER BY id DESC LIMIT 20
```

See `Cursor` + `CursorPage` (FT25) for the NeNe cursor pagination helpers.
