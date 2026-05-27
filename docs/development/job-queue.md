# Job Queue

`Nene\Xion\JobQueue` — simple DB-backed job queue for lightweight background task processing.

## Schema

```sql
CREATE TABLE job_queue (
    id           INTEGER      PRIMARY KEY AUTOINCREMENT,
    queue        VARCHAR(64)  NOT NULL DEFAULT 'default',
    payload      TEXT         NOT NULL,
    status       VARCHAR(16)  NOT NULL DEFAULT 'pending',
    attempts     INTEGER      NOT NULL DEFAULT 0,
    max_attempts INTEGER      NOT NULL DEFAULT 3,
    error        TEXT         DEFAULT NULL,
    available_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_job_queue_dequeue ON job_queue (queue, status, available_at);
```

## API

| Method | Description |
| --- | --- |
| `enqueue(array $payload, string $queue = 'default', int $maxAttempts = 3, int $delaySeconds = 0): int` | Add job. Returns ID. |
| `dequeue(string $queue = 'default'): ?array` | Claim next job atomically. Returns null when empty. |
| `complete(int $jobId): void` | Mark as completed. |
| `fail(int $jobId, string $error = ''): void` | Mark as failed or re-queue if retries remain. |
| `count(string $queue = 'default', ?string $status = null): int` | Count jobs (all or by status). |

## Status values

| Status | Meaning |
| --- | --- |
| `pending` | Waiting to be picked up. |
| `processing` | Claimed by a worker. |
| `completed` | Finished successfully. |
| `failed` | All attempts exhausted. |

## Usage

```php
$q = new JobQueue($pdo);

// Enqueue
$id = $q->enqueue(['type' => 'send_email', 'to' => 'user@example.com']);
$q->enqueue(['type' => 'resize_image', 'path' => '/tmp/img.jpg'], queue: 'images');
$q->enqueue(['type' => 'reminder'],  delaySeconds: 3600);  // run in 1 hour

// Worker loop
while ($job = $q->dequeue()) {
    try {
        $payload = $job['payload'];  // decoded array
        match ($payload['type']) {
            'send_email'    => sendEmail($payload),
            'resize_image'  => resizeImage($payload),
        };
        $q->complete($job['id']);
    } catch (\Throwable $e) {
        $q->fail($job['id'], $e->getMessage());
        // If attempts < max_attempts: status → 'pending' (retry)
        // If attempts >= max_attempts: status → 'failed' (dead)
    }
}

// Stats
$q->count('default', 'pending');    // waiting
$q->count('default', 'failed');     // dead-letter
$q->count('default');               // total
```

## Atomic dequeue

`dequeue()` uses a transaction to atomically:
1. `SELECT … LIMIT 1` for the oldest pending, available job
2. `UPDATE … SET status = 'processing', attempts = attempts + 1`

This prevents two workers from claiming the same job.

## Retry semantics

`fail()` checks `attempts` vs `max_attempts`:
- If `attempts < max_attempts` → status reverts to `'pending'` (job is re-queued)
- If `attempts >= max_attempts` → status set to `'failed'` (dead letter)

## Delayed jobs

`delaySeconds > 0` sets `available_at` to a future timestamp. The job is skipped by `dequeue()` until that time arrives.

## Key design points

- **FIFO**: `dequeue()` orders by `id ASC`.
- **Multi-queue**: jobs are isolated by `queue` name; use separate queues for priority tiers.
- **JSON payload**: `enqueue()` JSON-encodes; `dequeue()` JSON-decodes.
- **PDO injection**: `__construct(private readonly ?PDO $db = null)`.

## Test patterns

```php
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE job_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(64) NOT NULL DEFAULT \'default\',
    payload TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT \'pending\',
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    error TEXT DEFAULT NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
$q = new JobQueue($db);
```
