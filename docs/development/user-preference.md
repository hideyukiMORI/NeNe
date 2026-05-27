# User Preference

`Nene\Xion\UserPreference` — user preference key-value store with default-value fallback and type casting.

## Schema

```sql
CREATE TABLE user_preferences (
    user_id    VARCHAR(255) NOT NULL,
    pref_key   VARCHAR(255) NOT NULL,
    pref_value TEXT         NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, pref_key)
);
```

The composite primary key `(user_id, pref_key)` ensures one value per user per key. `pref_value` is always stored as a string; type conversion happens at the application layer.

## API

| Method | Description |
| --- | --- |
| `get(string $userId, string $key, ?string $default = null): ?string` | Get preference as string, or $default if not set. |
| `getInt(string $userId, string $key, int $default = 0): int` | Get preference cast to int. |
| `getBool(string $userId, string $key, bool $default = false): bool` | Get preference cast to bool. |
| `set(string $userId, string $key, string $value): void` | Create or overwrite a preference. |
| `delete(string $userId, string $key): bool` | Remove a preference. Returns true if deleted. |
| `all(string $userId): array<string, string>` | Get all preferences as a key→value map. |

## Usage

```php
$prefs = new UserPreference($pdo);

// Set
$prefs->set('user:1', 'theme', 'dark');
$prefs->set('user:1', 'items_per_page', '20');
$prefs->set('user:1', 'notifications', '1');

// Get with default fallback
$prefs->get('user:1', 'theme', 'light');            // 'dark' (stored)
$prefs->get('user:1', 'language', 'ja');            // 'ja' (default — not stored)

// Type casting
$prefs->getInt('user:1', 'items_per_page', 10);     // 20
$prefs->getBool('user:1', 'notifications', false);  // true

// Overwrite
$prefs->set('user:1', 'theme', 'light');
$prefs->get('user:1', 'theme');                     // 'light'

// Delete (revert to default)
$prefs->delete('user:1', 'theme');                  // true
$prefs->get('user:1', 'theme', 'light');            // 'light' (default)

// All preferences
$prefs->all('user:1');
// ['items_per_page' => '20', 'notifications' => '1']
```

## Boolean storage conventions

`getBool()` returns `true` for values `'1'`, `'true'`, `'yes'` (case-insensitive) and `false` for everything else.

Store booleans as `'1'` or `'0'` for consistency:

```php
$prefs->set('user:1', 'dark_mode', $isDark ? '1' : '0');
$prefs->getBool('user:1', 'dark_mode');  // true or false
```

## Key design points

- **String storage**: all values stored as `TEXT`; no type coercion in DB.
- **Upsert**: `set()` uses `INSERT OR REPLACE` (SQLite) / `INSERT … ON DUPLICATE KEY UPDATE` (MySQL).
- **Delete = revert to default**: removing a key causes `get()` to return the provided default.
- **User isolation**: all queries are scoped by `user_id`.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE user_preferences (
    user_id    VARCHAR(255) NOT NULL,
    pref_key   VARCHAR(255) NOT NULL,
    pref_value TEXT         NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, pref_key)
)');
$prefs = new UserPreference($db);
```
