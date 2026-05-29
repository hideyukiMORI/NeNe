# Distributed Lock

`Nene\Kit\DistributedLock` provides a DB-backed mutual exclusion lock for coordinating work across multiple processes or instances.

## When to use

- Preventing duplicate execution of scheduled jobs (cron, batch)
- Deduplication windows for idempotent operations
- Single-writer enforcement across multiple app instances

For high-contention hot paths (many requests per second competing for the same lock), prefer Redis `SETNX`; the DB-backed approach is well-suited to low-to-medium contention.

## Schema

```sql
CREATE TABLE distributed_locks (
    resource    VARCHAR(255) PRIMARY KEY,
    owner       VARCHAR(255) NOT NULL,
    expires_at  DATETIME     NOT NULL,
    acquired_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

## API

| Method | Returns | Description |
|--------|---------|-------------|
| `acquire(resource, owner, ttlSeconds)` | `bool` | Try to acquire the lock. Returns `true` on success, `false` if held by another owner. |
| `release(resource, owner)` | `bool` | Release the lock. Returns `false` if not owned by `$owner`. |
| `renew(resource, owner, ttlSeconds)` | `bool` | Extend the TTL. Returns `false` if expired, not found, or wrong owner. |
| `isHeld(resource)` | `bool` | Check whether any unexpired lock exists on `$resource`. |

## Basic usage

```php
use Nene\Kit\DistributedLock;

$lock  = new DistributedLock();
$owner = bin2hex(random_bytes(8)); // unique per process/request

if ($lock->acquire('report:monthly', $owner, ttlSeconds: 30)) {
    try {
        // ... do the exclusive work ...
    } finally {
        $lock->release('report:monthly', $owner);
    }
} else {
    // Another process holds the lock — skip or retry later
}
```

## Stale lock reclaim

If a process crashes while holding a lock, the TTL ensures the lock expires and the next caller can claim it automatically — no manual cleanup needed.

```php
// Stale lock left by crashed process:
// owner='worker-A', expires_at='2024-01-01 00:00:00' (past)

$lock->acquire('job:import', 'worker-B', 30);
// → true (expired lock claimed by worker-B)
```

## Renewing long-running jobs

For jobs that run longer than the initial TTL, renew periodically:

```php
$lock->acquire('job:heavy', $owner, 60);

foreach ($items as $item) {
    process($item);
    $lock->renew('job:heavy', $owner, 60); // reset TTL each iteration
}

$lock->release('job:heavy', $owner);
```

## Owner identity

Use a per-process or per-request unique string — never a fixed hostname:

```php
// Bad: same value after restart
$owner = gethostname();

// Good: unique per execution
$owner = bin2hex(random_bytes(8));   // 16-char hex
$owner = \Ramsey\Uuid\Uuid::uuid4()->toString(); // UUIDv4
```

A fixed hostname like `worker-1` becomes the same after restart, allowing a new process to accidentally reclaim a lock held by a crashed sibling with the same name.

## Error semantics

| Scenario | `release()` | `renew()` |
|----------|-------------|-----------|
| Lock found, correct owner | `true` | `true` |
| Lock found, wrong owner | `false` | `false` |
| Lock expired | `false` (DELETE matched 0 rows) | `false` (UPDATE matched 0 rows) |
| Lock not found | `false` | `false` |

## Testing without `sleep()`

Inject a PDO instance backed by SQLite in-memory and insert rows with crafted `expires_at` values to simulate expiry without sleeping:

```php
$db = new PDO('sqlite::memory:');
// ... create table ...
$db->exec("INSERT INTO distributed_locks (resource, owner, expires_at, acquired_at)
           VALUES ('job:x', 'owner-A', '2000-01-01 00:00:00', '2000-01-01 00:00:00')");

$lock = new DistributedLock($db);
$result = $lock->acquire('job:x', 'owner-B', 30); // stale reclaim → true
```
