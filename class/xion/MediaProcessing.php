<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * MediaProcessing — track async media conversion/processing jobs.
 *
 * When a user uploads a file, the raw asset goes into processing (thumbnail
 * generation, video transcoding, virus scan, etc.). This class tracks the job
 * state from `pending` through `processing` to `ready` or `failed`.
 *
 * Status lifecycle: `pending` → `processing` → `ready` | `failed`
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE media_jobs (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     job_id       VARCHAR(64)  NOT NULL UNIQUE,
 *     owner_id     VARCHAR(255) NOT NULL,
 *     source_path  TEXT         NOT NULL,
 *     output_path  TEXT         DEFAULT NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     error_msg    TEXT         DEFAULT NULL,
 *     attempts     INTEGER      NOT NULL DEFAULT 0,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class MediaProcessing
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly int $maxAttempts = self::MAX_ATTEMPTS
    ) {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Enqueue a new media processing job.
     *
     * @return string  The job ID (24 hex chars).
     * @throws \InvalidArgumentException if owner_id or source_path is empty.
     */
    public function enqueue(string $ownerId, string $sourcePath): string
    {
        $ownerId    = $this->validateField($ownerId, 'owner_id');
        $sourcePath = $this->validateField($sourcePath, 'source_path');
        $jobId      = bin2hex(random_bytes(12)); // 24 hex chars

        $this->db()->prepare(
            "INSERT INTO media_jobs (job_id, owner_id, source_path, status)
             VALUES (:jid, :oid, :src, 'pending')"
        )->execute([':jid' => $jobId, ':oid' => $ownerId, ':src' => $sourcePath]);

        return $jobId;
    }

    /**
     * Mark a job as in-progress (worker has picked it up).
     *
     * @return bool True if the job was found in 'pending' status and started.
     */
    public function start(string $jobId): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE media_jobs
             SET status = 'processing', attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP
             WHERE job_id = :jid AND status = 'pending'"
        );
        $stmt->execute([':jid' => trim($jobId)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a job as successfully completed.
     *
     * @param string $outputPath  The path/URL of the processed output.
     * @return bool True if the job was found in 'processing' status and completed.
     */
    public function complete(string $jobId, string $outputPath): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE media_jobs
             SET status = 'ready', output_path = :out, updated_at = CURRENT_TIMESTAMP
             WHERE job_id = :jid AND status = 'processing'"
        );
        $stmt->execute([':out' => $outputPath, ':jid' => trim($jobId)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a job as failed.
     *
     * If attempts < max_attempts, the job is reset to 'pending' for retry.
     * If attempts >= max_attempts, the job is permanently failed.
     *
     * @return bool True if the job was found and its failure was recorded.
     */
    public function fail(string $jobId, string $errorMsg = ''): bool
    {
        $job = $this->find($jobId);
        if ($job === null || $job['status'] !== 'processing') {
            return false;
        }

        if ((int)$job['attempts'] >= $this->maxAttempts) {
            $stmt = $this->db()->prepare(
                "UPDATE media_jobs
                 SET status = 'failed', error_msg = :err, updated_at = CURRENT_TIMESTAMP
                 WHERE job_id = :jid"
            );
        } else {
            // Reset to pending for retry
            $stmt = $this->db()->prepare(
                "UPDATE media_jobs
                 SET status = 'pending', error_msg = :err, updated_at = CURRENT_TIMESTAMP
                 WHERE job_id = :jid"
            );
        }
        $stmt->execute([':err' => $errorMsg, ':jid' => $jobId]);
        return true;
    }

    /**
     * Get a job by its ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $jobId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, job_id, owner_id, source_path, output_path, status, error_msg, attempts, created_at, updated_at
             FROM media_jobs WHERE job_id = :jid LIMIT 1'
        );
        $stmt->execute([':jid' => trim($jobId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the status of a job.
     *
     * @return 'pending'|'processing'|'ready'|'failed'|null
     */
    public function status(string $jobId): ?string
    {
        $job = $this->find($jobId);
        return $job !== null ? (string)$job['status'] : null;
    }

    /**
     * Get all jobs for an owner, most recent first.
     *
     * @param string|null $statusFilter  Optional status filter.
     * @return list<array<string,mixed>>
     */
    public function listForOwner(string $ownerId, ?string $statusFilter = null): array
    {
        $ownerId = trim($ownerId);
        if ($statusFilter !== null) {
            $stmt = $this->db()->prepare(
                'SELECT id, job_id, owner_id, source_path, output_path, status, attempts, created_at
                 FROM media_jobs WHERE owner_id = :oid AND status = :st ORDER BY id DESC'
            );
            $stmt->execute([':oid' => $ownerId, ':st' => $statusFilter]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, job_id, owner_id, source_path, output_path, status, attempts, created_at
                 FROM media_jobs WHERE owner_id = :oid ORDER BY id DESC'
            );
            $stmt->execute([':oid' => $ownerId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all pending jobs (for workers to pick up), oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingJobs(int $limit = 10): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT job_id, owner_id, source_path, attempts FROM media_jobs
             WHERE status = 'pending'
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Purge all completed or permanently failed jobs older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            "DELETE FROM media_jobs
             WHERE status IN ('ready', 'failed') AND updated_at < :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateField(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException("{$name} must not be empty.");
        }
        return $value;
    }
}
