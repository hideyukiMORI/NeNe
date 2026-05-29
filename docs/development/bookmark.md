# Bookmark

`Nene\Kit\Bookmark` — generic per-user bookmark / saved-item manager.

Users can bookmark any entity identified by `(entity_type, entity_id)`. An optional `collection` field groups bookmarks (e.g. `'wishlist'`, `'read-later'`, `'favourites'`).

## Schema

```sql
CREATE TABLE bookmarks (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    user_id     VARCHAR(255) NOT NULL,
    entity_type VARCHAR(64)  NOT NULL,
    entity_id   VARCHAR(255) NOT NULL,
    collection  VARCHAR(64)  NOT NULL DEFAULT '',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, entity_type, entity_id)
);
CREATE INDEX idx_bookmarks_user ON bookmarks (user_id, collection);
```

## API

| Method | Description |
| --- | --- |
| `save(string $userId, string $entityType, string $entityId, string $collection = ''): bool` | Bookmark an entity. Returns true if new, false if already exists. |
| `remove(string $userId, string $entityType, string $entityId): bool` | Remove bookmark. Returns true if deleted. |
| `isSaved(string $userId, string $entityType, string $entityId): bool` | Check bookmark status. |
| `list(string $userId, ?string $entityType = null, ?string $collection = null): array` | List bookmarks; optionally filter by type and/or collection. |

## Usage

```php
$bm = new Bookmark($pdo);

// Save
$bm->save('user:1', 'article', '42');                      // true
$bm->save('user:1', 'article', '99', 'read-later');        // true
$bm->save('user:1', 'article', '42');                      // false — already saved

// Check
$bm->isSaved('user:1', 'article', '42');   // true
$bm->isSaved('user:2', 'article', '42');   // false

// List all
$bm->list('user:1');
// [['id' => 2, 'entity_type' => 'article', 'entity_id' => '99', 'collection' => 'read-later', ...],
//  ['id' => 1, 'entity_type' => 'article', 'entity_id' => '42', 'collection' => '',           ...]]

// Filter by type
$bm->list('user:1', 'article');

// Filter by type + collection
$bm->list('user:1', 'article', 'read-later');

// Remove
$bm->remove('user:1', 'article', '42');  // true
$bm->remove('user:1', 'article', '42');  // false
```

## Collections

Collections are plain strings. Use whatever naming convention fits your app:

```php
$bm->save($userId, 'product', $productId, 'wishlist');
$bm->save($userId, 'article', $articleId, 'read-later');
$bm->save($userId, 'post',    $postId,    '');              // uncategorised
```

Pass `$collection = null` to `list()` to return all collections.

## Key design points

- **UNIQUE (user_id, entity_type, entity_id)**: prevents duplicate bookmarks at DB layer.
- **`save()` idempotent**: `INSERT OR IGNORE` / `INSERT IGNORE` — duplicate is a no-op returning `false`.
- **Collection default `''`**: uncategorised bookmarks use an empty string, not NULL.
- **`(entity_type, entity_id)`**: entity-agnostic — reuse across all content types.
- **`list()` order**: `created_at DESC, id DESC` — most recently bookmarked first.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE bookmarks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id VARCHAR(255) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(255) NOT NULL,
    collection VARCHAR(64) NOT NULL DEFAULT \'\',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, entity_type, entity_id)
)');
$bm = new Bookmark($db);
```
