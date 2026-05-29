<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DeadLetterQueue — parking lot for messages that exhausted their retries.
 *
 * When a job or message fails terminally (max attempts reached, poison
 * payload, unrecoverable error), the worker `record()`s it here with the
 * failing payload and error. Operators can then inspect, count, purge, or
 * `requeue()` entries for replay after a fix. Complements `JobQueue` (FT73)
 * and `NotificationQueue` (FT217), which handle live, retryable work — this
 * holds the *terminal failures* those queues give up on.
 *
 * Payloads are opaque strings (the caller JSON-encodes structured data). The
 * class never interprets them.
 *
 * ## Usage
 *
 * ```php
 * $dlq = new DeadLetterQueue($pdo);
 *
 * $id = $dlq->record('emails', json_encode($msg), 'SMTP 550', attempts: 5);
 *
 * $dlq->count('emails');             // how many parked
 * $dlq->forQueue('emails');          // inspect (newest first)
 * $dlq->queues();                    // [['queue'=>'emails','count'=>3], ...]
 *
 * // After fixing the bug, claim one for replay (returns it and removes it)
 * if ($entry = $dlq->requeue($id)) {
 *     // re-dispatch $entry['payload'] ...
 * }
 *
 * $dlq->purgeOlderThan(90);          // housekeeping
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE dead_letters (
 *     id        INTEGER PRIMARY KEY AUTOINCREMENT,
 *     queue     VARCHAR(100) NOT NULL,
 *     payload   TEXT         NOT NULL,
 *     error     TEXT         NOT NULL DEFAULT '',
 *     attempts  INTEGER      NOT NULL DEFAULT 0,
 *     failed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class DeadLetterQueue
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Park a terminally-failed message.
     *
     * @param  string $queue    Originating queue name.
     * @param  string $payload  Opaque payload (caller-encoded).
     * @param  string $error    Failure reason / last error.
     * @param  int    $attempts How many attempts were made (>= 0).
     * @return int              New dead-letter id.
     * @throws \InvalidArgumentException on empty queue or negative attempts.
     */
    public function record(string $queue, string $payload, string $error = '', int $attempts = 0): int
    {
        $queue = $this->validateQueue($queue);
        if ($attempts < 0) {
            throw new \InvalidArgumentException('Attempts must not be negative.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO dead_letters (queue, payload, error, attempts) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$queue, $payload, $error, $attempts]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Fetch a single dead letter by id.
     *
     * @return array{id:int,queue:string,payload:string,error:string,attempts:int,failed_at:string}|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, queue, payload, error, attempts, failed_at FROM dead_letters WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * List dead letters for a queue, newest failure first.
     *
     * @param  string   $queue Queue name.
     * @param  int|null $limit Optional cap on rows returned.
     * @return array<int,array{id:int,queue:string,payload:string,error:string,attempts:int,failed_at:string}>
     */
    public function forQueue(string $queue, ?int $limit = null): array
    {
        $queue = $this->validateQueue($queue);

        $sql = 'SELECT id, queue, payload, error, attempts, failed_at FROM dead_letters WHERE queue = ? ORDER BY id DESC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit);
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([$queue]);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * Count dead letters, optionally for a single queue.
     */
    public function count(?string $queue = null): int
    {
        if ($queue === null) {
            $stmt = $this->db()->query('SELECT COUNT(*) FROM dead_letters');

            return $stmt === false ? 0 : (int)$stmt->fetchColumn();
        }

        $queue = $this->validateQueue($queue);
        $stmt  = $this->db()->prepare('SELECT COUNT(*) FROM dead_letters WHERE queue = ?');
        $stmt->execute([$queue]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Per-queue summary counts, busiest first.
     *
     * @return array<int,array{queue:string,count:int}>
     */
    public function queues(): array
    {
        $stmt = $this->db()->query(
            'SELECT queue, COUNT(*) AS c FROM dead_letters GROUP BY queue ORDER BY c DESC, queue ASC'
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($rows as $row) {
            $out[] = ['queue' => (string)$row['queue'], 'count' => (int)$row['c']];
        }

        return $out;
    }

    /**
     * Remove a dead letter by id (e.g. discarded after review). No-op if absent.
     */
    public function remove(int $id): void
    {
        $stmt = $this->db()->prepare('DELETE FROM dead_letters WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * Claim a dead letter for replay: returns it and removes it atomically.
     *
     * @param  int $id Dead-letter id.
     * @return array{id:int,queue:string,payload:string,error:string,attempts:int,failed_at:string}|null
     *               The entry, or null if it does not exist.
     */
    public function requeue(int $id): ?array
    {
        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $entry = $this->get($id);
            if ($entry !== null) {
                $stmt = $db->prepare('DELETE FROM dead_letters WHERE id = ?');
                $stmt->execute([$id]);
            }
            if ($ownTransaction) {
                $db->commit();
            }

            return $entry;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete dead letters older than $days. Returns the number removed.
     *
     * @param  int         $days Age threshold in days (>= 0).
     * @param  string|null $asOf Reference time; defaults to now.
     */
    public function purgeOlderThan(int $days, ?string $asOf = null): int
    {
        if ($days < 0) {
            throw new \InvalidArgumentException('Days must not be negative.');
        }
        $ref = strtotime($asOf ?? 'now');
        if ($ref === false) {
            throw new \InvalidArgumentException('Invalid reference time.');
        }
        $cutoff = date('Y-m-d H:i:s', $ref - $days * 86400);

        $stmt = $this->db()->prepare('DELETE FROM dead_letters WHERE failed_at < ?');
        $stmt->execute([$cutoff]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed> $row
     * @return array{id:int,queue:string,payload:string,error:string,attempts:int,failed_at:string}
     */
    private function hydrate(array $row): array
    {
        return [
            'id'        => (int)$row['id'],
            'queue'     => (string)$row['queue'],
            'payload'   => (string)$row['payload'],
            'error'     => (string)$row['error'],
            'attempts'  => (int)$row['attempts'],
            'failed_at' => (string)$row['failed_at'],
        ];
    }

    private function validateQueue(string $queue): string
    {
        $queue = trim($queue);
        if ($queue === '') {
            throw new \InvalidArgumentException('Queue must not be empty.');
        }

        return $queue;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
