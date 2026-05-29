<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * BatchJob — batch processing job tracking with progress and status lifecycle.
 *
 * Tracks long-running batch operations (imports, exports, bulk updates) through
 * their lifecycle: pending → processing → done | failed. Supports incremental
 * progress updates so callers can display meaningful progress bars.
 *
 * ## Usage
 *
 * ```php
 * $bj = new BatchJob($pdo);
 *
 * // Enqueue
 * $jobId = $bj->enqueue('import_users', 'user-1', ['file' => 'users.csv']);
 *
 * // Processor picks up the job
 * $job = $bj->claim();
 * if ($job !== null) {
 *     $bj->progress($job['id'], 50, 200);  // 50 done out of 200 total
 *     $bj->complete($job['id'], 200);
 * }
 *
 * // Status checks
 * $bj->find($jobId);
 * $bj->listForUser('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE batch_jobs (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     type        VARCHAR(100) NOT NULL,
 *     owner_id    VARCHAR(255) NOT NULL DEFAULT '',
 *     params      TEXT         NOT NULL DEFAULT '',
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     total       INT          NOT NULL DEFAULT 0,
 *     processed   INT          NOT NULL DEFAULT 0,
 *     error       TEXT         NOT NULL DEFAULT '',
 *     enqueued_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     started_at  DATETIME     NULL,
 *     finished_at DATETIME     NULL
 * );
 * ```
 */
final class BatchJob
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Enqueue a new batch job.
     *
     * @param  string              $type     Job type identifier.
     * @param  string              $ownerId  User or system that owns this job.
     * @param  array<string,mixed> $params   Job-specific parameters (JSON-encoded).
     * @return int  The new job ID.
     * @throws \InvalidArgumentException if type is empty.
     */
    public function enqueue(string $type, string $ownerId = '', array $params = []): int
    {
        $type = $this->validateType($type);
        $stmt = $this->db()->prepare(
            'INSERT INTO batch_jobs (type, owner_id, params)
             VALUES (:type, :owner, :params)'
        );
        $stmt->execute([
            ':type'   => $type,
            ':owner'  => $ownerId,
            ':params' => $params !== [] ? json_encode($params, JSON_UNESCAPED_UNICODE) : '',
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Claim the oldest pending job and mark it as processing.
     *
     * Returns null if no pending jobs exist.
     *
     * @return array<string,mixed>|null
     */
    public function claim(): ?array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $db   = $this->db();

        // Fetch oldest pending job
        $stmt = $db->prepare(
            "SELECT id FROM batch_jobs WHERE status = 'pending' ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute();
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }

        $db->prepare(
            "UPDATE batch_jobs SET status = 'processing', started_at = :now WHERE id = :id AND status = 'pending'"
        )->execute([':now' => $now, ':id' => (int)$id]);

        return $this->find((int)$id);
    }

    /**
     * Update the processed count and (optionally) total for a running job.
     *
     * @param int $processed  Number of items processed so far.
     * @param int $total      Total item count (0 = unchanged).
     */
    public function progress(int $jobId, int $processed, int $total = 0): void
    {
        if ($total > 0) {
            $this->db()->prepare(
                'UPDATE batch_jobs SET processed = :p, total = :t WHERE id = :id'
            )->execute([':p' => max(0, $processed), ':t' => max(0, $total), ':id' => $jobId]);
        } else {
            $this->db()->prepare(
                'UPDATE batch_jobs SET processed = :p WHERE id = :id'
            )->execute([':p' => max(0, $processed), ':id' => $jobId]);
        }
    }

    /**
     * Mark the job as successfully completed.
     *
     * @param int $processed  Final processed count.
     */
    public function complete(int $jobId, int $processed = 0): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db()->prepare(
            "UPDATE batch_jobs
             SET status = 'done', processed = :p, finished_at = :now
             WHERE id = :id"
        )->execute([':p' => max(0, $processed), ':now' => $now, ':id' => $jobId]);
    }

    /**
     * Mark the job as failed with an error message.
     */
    public function fail(int $jobId, string $error = ''): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db()->prepare(
            "UPDATE batch_jobs
             SET status = 'failed', error = :error, finished_at = :now
             WHERE id = :id"
        )->execute([':error' => $error, ':now' => $now, ':id' => $jobId]);
    }

    /**
     * Find a job by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $jobId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, type, owner_id, params, status, total, processed, error,
                    enqueued_at, started_at, finished_at
             FROM batch_jobs WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List jobs for an owner, newest first.
     *
     * @param  string|null $status  Filter by status (null = all).
     * @return list<array<string,mixed>>
     */
    public function listForOwner(string $ownerId, int $limit = 20, ?string $status = null): array
    {
        $limit = max(1, $limit);
        if ($status !== null) {
            $stmt = $this->db()->prepare(
                "SELECT id, type, owner_id, status, total, processed, enqueued_at, started_at, finished_at
                 FROM batch_jobs WHERE owner_id = :owner AND status = :status
                 ORDER BY id DESC LIMIT {$limit}"
            );
            $stmt->execute([':owner' => $ownerId, ':status' => $status]);
        } else {
            $stmt = $this->db()->prepare(
                "SELECT id, type, owner_id, status, total, processed, enqueued_at, started_at, finished_at
                 FROM batch_jobs WHERE owner_id = :owner
                 ORDER BY id DESC LIMIT {$limit}"
            );
            $stmt->execute([':owner' => $ownerId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count jobs by status.
     *
     * @param string|null $status  Filter by status (null = all).
     */
    public function count(?string $status = null): int
    {
        if ($status !== null) {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM batch_jobs WHERE status = :status');
            $stmt->execute([':status' => $status]);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM batch_jobs');
            $stmt->execute();
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete completed or failed jobs older than N days (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            "DELETE FROM batch_jobs
             WHERE status IN ('done', 'failed') AND finished_at < :cutoff"
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
            throw new \InvalidArgumentException('Job type must not be empty.');
        }
        return $type;
    }
}
