# Idempotency Keys

How to prevent duplicate operations when a client retries a POST request:
store the first response in a DB-backed key cache; replay it on all subsequent
requests that carry the same key.

## Why idempotency keys

Network retries are unavoidable. Without a guard, a retry for "create order"
creates two orders. Idempotency keys let the server detect the retry and return
the cached response instead of executing the operation a second time.

The pattern is standard in payment APIs (Stripe, Adyen) and is appropriate for
any non-idempotent POST endpoint where double-execution has a user-visible cost.

## Schema

Add the table once to `class/xion/SchemaDefinition.php`:

```php
'idempotency_keys' => [
    'columns' => [
        'key_hash'    => ['type' => 'varchar:64'],           // SHA-256 of raw key; PRIMARY KEY
        'status_code' => ['type' => 'smallint'],
        'body'        => ['type' => 'text'],                 // JSON-encoded response body
        'created_at'  => ['type' => 'datetime-now'],
    ],
    'primary' => 'key_hash',                                 // if SchemaCompiler supports it
],
```

Or as raw SQL for a one-off migration:

```sql
CREATE TABLE idempotency_keys (
    key_hash    VARCHAR(64) PRIMARY KEY,
    status_code SMALLINT    NOT NULL,
    body        TEXT        NOT NULL,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

The raw key is never stored — only its SHA-256 hash — so arbitrarily long or
Unicode keys are safe.

## Controller pattern

```php
use Nene\Xion\IdempotencyStore;

public function createPostRest(): array
{
    $rawKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
    $store  = new IdempotencyStore();

    // 1. Check for a cached response
    if ($rawKey !== '') {
        $cached = $store->get($rawKey);
        if ($cached !== null) {
            header('X-Idempotent-Replayed: true');
            http_response_code($cached['status_code']);
            echo $cached['body'];
            exit;
        }
    }

    // 2. Validate input and perform the operation
    $post = $this->POST->getArray(['title', 'description']);
    // ... validation ...
    $id = (new TodoMapper())->create($post);

    // 3. Build the response
    $result       = ['id' => $id];
    $responseBody = json_encode($result, JSON_THROW_ON_ERROR);

    // 4. Cache before returning so retries replay the same response
    if ($rawKey !== '') {
        $store->put($rawKey, 201, $responseBody);
    }

    http_response_code(201);
    echo $responseBody;
    exit;
}
```

Keep the `get` → early-return → operate → `put` sequence. Storing the response
after the operation (step 4) means the first call always executes the logic;
only retries are replayed.

## Request header

Clients send the key in the `X-Idempotency-Key` header:

```
POST /api/todo HTTP/1.1
X-Idempotency-Key: 550e8400-e29b-41d4-a716-446655440000
Content-Type: application/json

{"title": "Buy milk"}
```

The key value is opaque to the server — a UUIDv4 is the conventional choice
because it is globally unique per client without coordination.

## Response header

When a replayed response is returned, add `X-Idempotent-Replayed: true` so
clients can distinguish a fresh response from a replay:

```
HTTP/1.1 201 Created
X-Idempotent-Replayed: true
Content-Type: application/json

{"id": 42}
```

## IdempotencyStore API

`class/xion/IdempotencyStore.php` — `Nene\Xion\IdempotencyStore`

| Method | Signature | Description |
|---|---|---|
| `get` | `get(string $rawKey): ?array` | Returns `['status_code' => int, 'body' => string]` or `null` if not found. |
| `put` | `put(string $rawKey, int $statusCode, string $body): void` | Stores the response. Silently ignores duplicate-key violations (concurrent retries). |
| `hash` | `hash(string $rawKey): string` | Returns the 64-char SHA-256 hex of the raw key. |

The constructor accepts an optional `PDO $db` argument for testing:

```php
$store = new IdempotencyStore();                    // uses PdoConnection::getInstance()
$store = new IdempotencyStore($testPdo);            // injected PDO (e.g. SQLite :memory:)
$store = new IdempotencyStore($pdo, 'my_table');    // custom table name
```

MySQL uses `INSERT IGNORE`; SQLite uses `INSERT OR IGNORE`. The driver is
detected at runtime via `PDO::ATTR_DRIVER_NAME`.

## Security

**Maximum key length** — validate that `X-Idempotency-Key` does not exceed a
reasonable limit (256 bytes is a safe cap) before passing it to the store:

```php
$rawKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? '';
if (strlen($rawKey) > 256) {
    // reject with 400
}
```

Since the key is hashed before storage, the DB column is always 64 chars
regardless of input length; the validation is only to protect against
intentionally oversized requests.

**TTL and purge strategy** — idempotency keys should not accumulate forever.
Two options:

1. **Cron purge:** delete rows older than a fixed window (24 h is common for
   payment APIs) with a scheduled job:
   ```sql
   DELETE FROM idempotency_keys WHERE created_at < NOW() - INTERVAL 24 HOUR;
   ```

2. **On-read TTL:** check `created_at` in `get()` and treat expired rows as
   cache misses. Requires adding the expiry logic to `IdempotencyStore::get()`.

Choose option 1 (cron purge) for simplicity unless the table is expected to
grow very large.

**Key scope** — if the service is multi-tenant, include the user or tenant ID
as part of the raw key before hashing, or store a `user_id` column and enforce
it in `get()`. Otherwise a user who guesses another user's key could replay
their response.

## Related

- `class/xion/IdempotencyStore.php` — implementation
- `tests/Unit/Xion/IdempotencyStoreTest.php` — unit tests
- `docs/development/ledger-systems.md` — UNIQUE constraint pattern (related concept)
