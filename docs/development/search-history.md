# Search History

`Nene\Kit\SearchHistory` — per-user search history with deduplication, auto-trimming, and limit control.

## Schema

```sql
CREATE TABLE search_history (
    id          INTEGER      PRIMARY KEY AUTOINCREMENT,
    user_id     VARCHAR(255) NOT NULL,
    query       VARCHAR(255) NOT NULL,
    searched_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, query)
);
CREATE INDEX idx_search_history_user ON search_history (user_id, searched_at DESC);
```

The `UNIQUE (user_id, query)` constraint enables upsert — pushing an existing query updates its timestamp instead of creating a duplicate.

## API

| Method | Description |
| --- | --- |
| `push(string $userId, string $query): void` | Add/refresh a query. Trims oldest entries beyond max. |
| `recent(string $userId, int $limit = 10): array` | Recent queries, newest first. |
| `clear(string $userId): int` | Remove all entries. Returns count deleted. |

## Usage

```php
$sh = new SearchHistory($pdo);

$sh->push('user:1', 'php arrays');
$sh->push('user:1', 'oop patterns');
$sh->push('user:1', 'php arrays');  // moves to top, no duplicate

$sh->recent('user:1');
// ['php arrays', 'oop patterns']

$sh->recent('user:1', 1);
// ['php arrays']

$sh->clear('user:1');  // returns 2
$sh->recent('user:1'); // []
```

## Deduplication

When a query is pushed that already exists for the user:
- The `searched_at` timestamp is updated (upsert via `INSERT OR REPLACE`)
- The query moves to the top of `recent()`
- No duplicate row is created

## Auto-trim

After each push, entries beyond `$maxEntries` (default 20) are deleted, oldest first. The trim is based on `searched_at ASC` so the most recently searched queries are retained.

Override `maxEntries` via constructor:

```php
$sh = new SearchHistory($pdo, maxEntries: 10);
```

## Key design points

- **UNIQUE constraint**: `(user_id, query)` — guarantees one row per user per query.
- **Upsert**: `INSERT OR REPLACE` (SQLite) / `INSERT … ON DUPLICATE KEY UPDATE` (MySQL).
- **Whitespace trim**: `push()` strips leading/trailing whitespace from the query.
- **Empty guard**: throws `InvalidArgumentException` for empty/whitespace-only queries.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE search_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id VARCHAR(255) NOT NULL,
    query VARCHAR(255) NOT NULL,
    searched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, query)
)');
$sh = new SearchHistory($db);
```
