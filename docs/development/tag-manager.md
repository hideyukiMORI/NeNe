# Tag Manager

`Nene\Kit\TagManager` — generic M:N tag/label system for any entity type.

Tags are reusable and shared across entities. A tag named `'php'` exists once in the `tags` table and can be attached to any number of posts, products, tasks, etc. Entity identity uses `(entity_type, entity_id)` pairs to keep the helper application-agnostic.

## Schema

```sql
CREATE TABLE tags (
    id         INTEGER      PRIMARY KEY AUTOINCREMENT,
    name       VARCHAR(100) NOT NULL UNIQUE,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tag_attachments (
    tag_id      INTEGER      NOT NULL,
    entity_type VARCHAR(64)  NOT NULL,
    entity_id   VARCHAR(255) NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tag_id, entity_type, entity_id)
);
CREATE INDEX idx_tag_attachments_entity ON tag_attachments (entity_type, entity_id);
```

## API

| Method | Description |
| --- | --- |
| `attach(string $entityType, string $entityId, array $tagNames): void` | Attach tags. Creates tags if they don't exist. Idempotent. |
| `detach(string $entityType, string $entityId, string $tagName): bool` | Remove one tag. Returns true if detached. |
| `syncTags(string $entityType, string $entityId, array $tagNames): void` | Replace all tags atomically. Empty array removes all. |
| `tagsFor(string $entityType, string $entityId): array` | Tag names for an entity (alphabetical). |
| `entitiesWithTag(string $tagName, ?string $entityType = null): array` | Entity IDs with the given tag, optionally filtered by type. |

## Usage

```php
$tags = new TagManager($pdo);

// Attach (creates tags automatically, idempotent)
$tags->attach('post', '42', ['php', 'oop', 'design-patterns']);

// Get tags for a post
$tags->tagsFor('post', '42');
// ['design-patterns', 'oop', 'php']  ← alphabetical

// Detach one tag
$tags->detach('post', '42', 'oop');  // true

// Sync (replace all)
$tags->syncTags('post', '42', ['php', 'solid']);
// result: ['php', 'solid']

// Find posts with a tag
$tags->entitiesWithTag('php', 'post');
// ['42', '99', ...]

// Cross-type (all entity types with this tag)
$tags->entitiesWithTag('php');
// ['42', '99', 'product:7', ...]
```

## Key design points

- **Tag reuse**: a tag name exists once in `tags`; `attach()` uses `INSERT OR IGNORE` for the tag row.
- **Idempotent attach**: duplicate `(tag_id, entity_type, entity_id)` rows are silently ignored.
- **`syncTags()` atomicity**: DELETE all existing + INSERT new in a transaction.
- **Type isolation**: `tagsFor('post', '1')` and `tagsFor('product', '1')` are independent.
- **`entitiesWithTag()` type filter**: pass `$entityType = null` to query across all types.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$db->exec('CREATE TABLE tag_attachments (
    tag_id INTEGER NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tag_id, entity_type, entity_id)
)');
$tags = new TagManager($db);
```
