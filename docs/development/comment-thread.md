# Comment Thread

`Nene\Xion\CommentThread` — threaded comment system with nesting depth limit and soft delete.

## Schema

```sql
CREATE TABLE comments (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    entity_type VARCHAR(64)  NOT NULL,
    entity_id   VARCHAR(255) NOT NULL,
    parent_id   INTEGER      DEFAULT NULL,
    author_id   VARCHAR(255),
    body        TEXT         NOT NULL,
    depth       INTEGER      NOT NULL DEFAULT 0,
    deleted_at  DATETIME     DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_comments_entity ON comments (entity_type, entity_id, parent_id);
```

## API

| Method | Description |
| --- | --- |
| `post(string $entityType, string $entityId, string $authorId, string $body): int` | Post a top-level comment. Returns new ID. |
| `reply(int $parentId, string $authorId, string $body): int` | Reply to a comment. Returns new ID. |
| `softDelete(int $commentId, string $authorId): bool` | Soft-delete (author-enforced). Returns true if deleted. |
| `list(string $entityType, string $entityId): array` | All comments for an entity (ASC, includes deleted). |

## Usage

```php
$ct = new CommentThread($pdo);

// Top-level comment
$id1 = $ct->post('article', '42', 'user:1', 'Great read!');

// Reply (depth = parent.depth + 1)
$id2 = $ct->reply($id1, 'user:2', 'Agreed!');
$id3 = $ct->reply($id2, 'user:3', 'Me too!');  // depth 2

// List (newest → oldest via ORDER BY id ASC)
$comments = $ct->list('article', '42');
// [
//   ['id' => 1, 'parent_id' => null, 'author_id' => 'user:1', 'body' => 'Great read!', 'depth' => 0, ...],
//   ['id' => 2, 'parent_id' => 1,    'author_id' => 'user:2', 'body' => 'Agreed!',     'depth' => 1, ...],
//   ...
// ]

// Soft delete (body replaced, author_id nulled, row retained for threading)
$ct->softDelete($id1, 'user:1');  // true
$ct->softDelete($id1, 'user:2');  // false — wrong author
```

## Soft delete behavior

When a comment is soft-deleted:
- `body` is set to `'[deleted]'`
- `author_id` is set to `null`
- `deleted_at` is set to the current UTC timestamp
- The row is **retained** — replies remain threaded correctly

`list()` includes soft-deleted comments so the thread structure is intact. Applications should render `[deleted]` comments differently based on `deleted_at !== null`.

## Depth limit

The default maximum nesting depth is 5 (depth 0 = top-level, depth 5 = deepest reply). Exceeding it throws `InvalidArgumentException`. Override via constructor:

```php
$ct = new CommentThread($pdo, maxDepth: 3);
```

## Key design points

- **`(entity_type, entity_id)`**: entity-agnostic — reusable across any content type.
- **`depth` column**: stored on insert for fast flat-list rendering without tree traversal.
- **Soft delete**: row retained; author and body redacted. Reply structure preserved.
- **Author enforcement**: `softDelete()` requires matching `author_id`.
- **Empty body guard**: `post()` and `reply()` throw `InvalidArgumentException` for empty/whitespace-only body.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(255) NOT NULL,
    parent_id INTEGER DEFAULT NULL,
    author_id VARCHAR(255),
    body TEXT NOT NULL,
    depth INTEGER NOT NULL DEFAULT 0,
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$ct = new CommentThread($db);
```
