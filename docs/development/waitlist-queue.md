# Waitlist / FIFO Queue

How to implement a FIFO waitlist where users join named queues and are served in order. Position is derived (never stored), and the history is preserved via soft-status.

## The position-derivation pattern

**Do not store a position number.** Stored positions become stale the moment any entry ahead of the target is served or leaves. Instead, compute position on demand:

```sql
SELECT COUNT(*) AS position
FROM waitlist_entries
WHERE queue = :queue
  AND status = 'waiting'
  AND joined_at <= :target_joined_at
```

This returns the user's 1-based ordinal position. The result is always accurate without any update cascade.

## Schema

```php
// class/xion/SchemaDefinition.php

'waitlist_entries' => [
    'columns' => [
        'id'        => ['type' => 'pk-bigint'],
        'created_at'=> ['type' => 'datetime-now'],
        'updated_at'=> ['type' => 'datetime-touch'],
        'queue'     => ['type' => 'varchar:128'],      // named queue identifier
        'user_id'   => ['type' => 'bigint'],
        'status'    => ['type' => 'varchar:16'],        // waiting | served | left
        'note'      => ['type' => 'text'],
        'joined_at' => ['type' => 'varchar:19'],        // UTC, YYYY-MM-DD HH:MM:SS
    ],
    'unique' => [
        // One entry per (queue, user_id) across all statuses
        'waitlist_queue_user_unique' => ['queue', 'user_id'],
    ],
    'indexes' => [
        'waitlist_queue_status_index' => ['queue', 'status'],
    ],
],
```

## Mapper

```php
// class/db/WaitlistMapper.php

class WaitlistMapper extends \Nene\Xion\DataMapperBase
{
    const TARGET_TABLE = 'waitlist_entries';
    const KEY_SID      = 'id';

    /** @return array|null null = already in queue (409) */
    public function join(string $queue, int $userId, string $note = ''): ?array
    {
        $joinedAt = (new \DateTime())->format('Y-m-d H:i:s');
        $stmt = $this->DB->prepare('
            INSERT INTO ' . static::TARGET_TABLE . ' (queue, user_id, status, note, joined_at)
            VALUES (:queue, :uid, :status, :note, :joined)
        ');
        $stmt->bindValue(':queue',  $queue,    PDO::PARAM_STR);
        $stmt->bindValue(':uid',    $userId,   PDO::PARAM_INT);
        $stmt->bindValue(':status', 'waiting', PDO::PARAM_STR);
        $stmt->bindValue(':note',   $note,     PDO::PARAM_STR);
        $stmt->bindValue(':joined', $joinedAt, PDO::PARAM_STR);
        try {
            $this->execute($stmt);
        } catch (\PDOException $e) {
            if (str_starts_with((string)$e->getCode(), '23')) { // constraint violation
                return null; // caller maps to 409
            }
            throw $e;
        }

        $id  = (int)$this->DB->lastInsertId();
        $row = $this->findRowById($id);
        $row['position'] = $this->computePosition($queue, $joinedAt);
        return $row;
    }

    /** @return list<array<string, mixed>> entries in FIFO order with sequential position */
    public function listWaiting(string $queue): array
    {
        $stmt = $this->DB->prepare('
            SELECT * FROM ' . static::TARGET_TABLE . '
            WHERE queue = :queue AND status = :status
            ORDER BY joined_at ASC
        ');
        $stmt->bindValue(':queue',  $queue,    PDO::PARAM_STR);
        $stmt->bindValue(':status', 'waiting', PDO::PARAM_STR);
        $rows = $this->execute($stmt)->fetchAll(PDO::FETCH_ASSOC);
        // Assign sequential positions in PHP — no N+1 queries
        foreach ($rows as $i => &$row) {
            $row['position'] = $i + 1;
        }
        unset($row);
        return $rows;
    }

    public function getPosition(string $queue, int $userId): ?array
    {
        $stmt = $this->DB->prepare('
            SELECT * FROM ' . static::TARGET_TABLE . '
            WHERE queue = :queue AND user_id = :uid AND status = :status
            LIMIT 1
        ');
        $stmt->bindValue(':queue',  $queue,    PDO::PARAM_STR);
        $stmt->bindValue(':uid',    $userId,   PDO::PARAM_INT);
        $stmt->bindValue(':status', 'waiting', PDO::PARAM_STR);
        $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['position'] = $this->computePosition($queue, $row['joined_at']);
        return $row;
    }

    /** Serve the first waiting entry; returns the served row or null if queue is empty */
    public function advance(string $queue): ?array
    {
        $transaction = new \Nene\Xion\TransactionManager();
        return $transaction->run(function () use ($queue): ?array {
            $stmt = $this->DB->prepare('
                SELECT * FROM ' . static::TARGET_TABLE . '
                WHERE queue = :queue AND status = :status
                ORDER BY joined_at ASC
                LIMIT 1
                FOR UPDATE
            ');
            $stmt->bindValue(':queue',  $queue,    PDO::PARAM_STR);
            $stmt->bindValue(':status', 'waiting', PDO::PARAM_STR);
            $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null; // empty queue
            }

            $upd = $this->DB->prepare('
                UPDATE ' . static::TARGET_TABLE . ' SET status = :served WHERE id = :id
            ');
            $upd->bindValue(':served', 'served', PDO::PARAM_STR);
            $upd->bindValue(':id',     $row['id'], PDO::PARAM_INT);
            $this->execute($upd);

            return $this->findRowById((int)$row['id']);
        });
    }

    public function leave(string $queue, int $userId): bool
    {
        $stmt = $this->DB->prepare('
            UPDATE ' . static::TARGET_TABLE . '
            SET status = :left
            WHERE queue = :queue AND user_id = :uid AND status = :waiting
        ');
        $stmt->bindValue(':left',    'left',    PDO::PARAM_STR);
        $stmt->bindValue(':queue',   $queue,    PDO::PARAM_STR);
        $stmt->bindValue(':uid',     $userId,   PDO::PARAM_INT);
        $stmt->bindValue(':waiting', 'waiting', PDO::PARAM_STR);
        $affected = $this->execute($stmt)->rowCount();
        return $affected > 0;
    }

    private function computePosition(string $queue, string $joinedAt): int
    {
        $stmt = $this->DB->prepare('
            SELECT COUNT(*) AS pos FROM ' . static::TARGET_TABLE . '
            WHERE queue = :queue AND status = :status AND joined_at <= :joined
        ');
        $stmt->bindValue(':queue',  $queue,    PDO::PARAM_STR);
        $stmt->bindValue(':status', 'waiting', PDO::PARAM_STR);
        $stmt->bindValue(':joined', $joinedAt, PDO::PARAM_STR);
        $row = $this->execute($stmt)->fetch(PDO::FETCH_ASSOC);
        return (int)($row['pos'] ?? 1);
    }
}
```

> **SQLite note:** `FOR UPDATE` is MySQL only. For SQLite, use `BEGIN EXCLUSIVE` (`$this->DB->exec('BEGIN EXCLUSIVE')`) or rely on SQLite's file-level locking.

## Error codes

```php
// config/error_codes.php
'WAITLIST-ALREADY-JOINED' => ['message' => 'You are already in this queue.', 'httpStatus' => 409],
'WAITLIST-NOT-IN-QUEUE'   => ['message' => 'You are not in this queue.',     'httpStatus' => 404],
'WAITLIST-EMPTY'          => ['message' => 'The queue is empty.',             'httpStatus' => 404],
```

## Design decisions

| Decision | Recommendation |
|---|---|
| Position storage | Derived via COUNT — never stored. Avoids stale data on concurrent exits. |
| Soft-status model | Rows are never deleted. `status IN ('waiting', 'served', 'left')` preserves history. |
| Re-join after leaving | Not supported with soft-status + UNIQUE constraint. Remove the UNIQUE or add application-layer logic if re-join is required. |
| Position in list response | Assign sequentially in PHP after ORDER BY `joined_at ASC` — avoids N+1. |
| Concurrent advance | `FOR UPDATE` (MySQL) or `BEGIN EXCLUSIVE` (SQLite) prevents two `advance` calls serving the same entry. |
| Empty queue | `advance` returns `null` → 404, not 409. There is nothing wrong with the request; the queue is just empty. |

## Related

- `docs/development/booking-systems.md` — capacity check with SELECT FOR UPDATE
- `docs/development/state-machines.md` — status transition patterns
- `docs/development/ledger-systems.md` — idempotency key / UNIQUE constraint patterns
