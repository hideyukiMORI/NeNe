<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * JobQueue — simple DB-backed background job queue.
 *
 * Jobs are enqueued with a type, JSON payload, and optional delay.
 * Workers claim a single job atomically, process it, then mark it done or failed.
 * Failed jobs are retried up to a configurable maximum; after that they are
 * moved to a permanent `failed` status.
 *
 * Status lifecycle: `pending` → `running` → `done` | `failed`
 *
 * ## Usage
 *
 * ```php
 * $jq = new JobQueue($pdo);
 *
 * // Enqueue a job
 * $id = $jq->enqueue('send_email', ['to' => 'user@example.com']);
 *
 * // Claim next available job
 * $job = $jq->claim();
 *
 * if ($job !== null) {
 *     // ... process ...
 *     $jq->complete($job['id']);
 *     // or on failure:
 *     $jq->fail($job['id'], 'SMTP timeout');
 * }
 *
 * // Inspect queue
 * $jq->count('pending');
 * $jq->listPending(10);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE job_queue (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     type         VARCHAR(100) NOT NULL,
 *     payload      TEXT         NOT NULL DEFAULT '{}',
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     attempts     INT          NOT NULL DEFAULT 0,
 *     max_attempts INT          NOT NULL DEFAULT 3,
 *     error        TEXT         NOT NULL DEFAULT '',
 *     run_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     claimed_at   DATETIME     DEFAULT NULL,
 *     done_at      DATETIME     DEFAULT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class JobQueue
{
    public function __construct(
        private readonly ?PDO $db = null,
        private readonly int $maxAttempts = 3,
    ) {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Enqueue a new job.
     *
     * @param  array<string,mixed> $payload      Arbitrary data passed to the worker.
     * @param  int                 $delaySeconds Delay before the job becomes available.
     * @return int The new job ID.
     * @throws \InvalidArgumentException if type is empty.
     */
    public function enqueue(string $type, array $payload = [], int $delaySeconds = 0): int
    {
        $type  = $this->validateType($type);
        $db    = $this->db();
        $runAt = (new \DateTimeImmutable())->modify("+{$delaySeconds} seconds")->format('Y-m-d H:i:s');

        $db->prepare(
            'INSERT INTO job_queue (type, payload, max_attempts, run_at)
             VALUES (:type, :payload, :max, :run_at)'
        )->execute([
            ':type'    => $type,
            ':payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ':max'     => $this->maxAttempts,
            ':run_at'  => $runAt,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Claim the next available pending job for processing.
     *
     * A claimed job transitions to `running` and has its attempt counter
     * incremented. Returns null if no jobs are available.
     *
     * @return array<string,mixed>|null
     */
    public function claim(): ?array
    {
        $db  = $this->db();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            "SELECT id FROM job_queue
             WHERE status = 'pending' AND run_at <= :now
             ORDER BY run_at ASC, id ASC
             LIMIT 1"
        );
        $stmt->execute([':now' => $now]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return null;
        }

        $stmt = $db->prepare(
            "UPDATE job_queue
             SET status = 'running', claimed_at = :now, attempts = attempts + 1
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':now' => $now, ':id' => (int)$id]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->find((int)$id);
    }

    /**
     * Mark a running job as successfully completed.
     *
     * @return bool True if the job was running and is now done.
     */
    public function complete(int $jobId): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE job_queue
             SET status = 'done', done_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = 'running'"
        );
        $stmt->execute([':id' => $jobId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a running job as failed.
     *
     * If attempts < max_attempts the job is reset to `pending` for retry.
     * Otherwise it is permanently marked `failed`.
     *
     * @return bool True if the job was running.
     */
    public function fail(int $jobId, string $error = ''): bool
    {
        $db   = $this->db();
        $stmt = $db->prepare(
            "SELECT attempts, max_attempts FROM job_queue WHERE id = :id AND status = 'running'"
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        if ((int)$row['attempts'] < (int)$row['max_attempts']) {
            $delay = (int)$row['attempts'] * 60;
            $runAt = (new \DateTimeImmutable())->modify("+{$delay} seconds")->format('Y-m-d H:i:s');
            $db->prepare(
                "UPDATE job_queue
                 SET status = 'pending', error = :error, claimed_at = NULL, run_at = :run_at
                 WHERE id = :id"
            )->execute([':error' => $error, ':run_at' => $runAt, ':id' => $jobId]);
        } else {
            $db->prepare(
                "UPDATE job_queue
                 SET status = 'failed', error = :error, done_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            )->execute([':error' => $error, ':id' => $jobId]);
        }

        return true;
    }

    /**
     * Release a running job back to pending (worker crash recovery).
     *
     * @return bool True if the job was running and is now pending.
     */
    public function release(int $jobId): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE job_queue
             SET status = 'pending', claimed_at = NULL
             WHERE id = :id AND status = 'running'"
        );
        $stmt->execute([':id' => $jobId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get a single job by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $jobId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, type, payload, status, attempts, max_attempts, error,
                    run_at, claimed_at, done_at, created_at
             FROM job_queue WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['payload'] = json_decode((string)$row['payload'], true) ?? [];
        return $row;
    }

    /**
     * List pending jobs (run_at <= now), optionally filtered by type.
     *
     * @return list<array<string,mixed>>
     */
    public function listPending(int $limit = 20, ?string $type = null): array
    {
        $limit = max(1, $limit);
        $now   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($type !== null) {
            $stmt = $this->db()->prepare(
                "SELECT id, type, payload, status, attempts, run_at, created_at
                 FROM job_queue
                 WHERE status = 'pending' AND run_at <= :now AND type = :type
                 ORDER BY run_at ASC, id ASC
                 LIMIT {$limit}"
            );
            $stmt->execute([':now' => $now, ':type' => $type]);
        } else {
            $stmt = $this->db()->prepare(
                "SELECT id, type, payload, status, attempts, run_at, created_at
                 FROM job_queue
                 WHERE status = 'pending' AND run_at <= :now
                 ORDER BY run_at ASC, id ASC
                 LIMIT {$limit}"
            );
            $stmt->execute([':now' => $now]);
        }

        return array_map(
            static function (array $row): array {
                $row['payload'] = json_decode((string)$row['payload'], true) ?? [];
                return $row;
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Count jobs, optionally filtered by status.
     */
    public function count(?string $status = null): int
    {
        if ($status !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM job_queue WHERE status = :status'
            );
            $stmt->execute([':status' => $status]);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM job_queue');
            $stmt->execute();
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete completed/failed jobs older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeCompleted(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            "DELETE FROM job_queue
             WHERE status IN ('done', 'failed') AND done_at < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateType(string $type): string
    {
        $type = trim($type);
        if ($type === '') {
            throw new \InvalidArgumentException('type must not be empty.');
        }
        return $type;
    }
}
