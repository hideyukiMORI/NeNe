<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ExportJob — async data export job tracking.
 *
 * Manages the lifecycle of long-running data export tasks (CSV, JSON, XLSX, …).
 * A job is enqueued, picked up by a worker, completed with a filename and row
 * count, or marked as failed with an error message.
 *
 * ## Status lifecycle
 *
 * ```
 * pending → processing → done
 *                      ↘ failed
 * ```
 *
 * ## Usage
 *
 * ```php
 * $ej = new ExportJob($pdo);
 *
 * // Enqueue
 * $jobId = $ej->enqueue('user-1', 'csv', 'orders');
 *
 * // Worker picks it up
 * $ej->start($jobId);
 *
 * // Worker completes
 * $ej->complete($jobId, 'exports/orders-2026-05-27.csv', 1234);
 *
 * // or fails
 * $ej->fail($jobId, 'Database timeout');
 *
 * // User polls status
 * $ej->find($jobId);
 * $ej->listForUser('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE export_jobs (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     format       VARCHAR(50)  NOT NULL DEFAULT 'csv',
 *     label        VARCHAR(255) NOT NULL DEFAULT '',
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     filename     VARCHAR(500) NOT NULL DEFAULT '',
 *     row_count    INTEGER      NOT NULL DEFAULT 0,
 *     error        TEXT         NOT NULL DEFAULT '',
 *     started_at   DATETIME     DEFAULT NULL,
 *     finished_at  DATETIME     DEFAULT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ExportJob
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Enqueue an export job.
     *
     * @param string $format Export format (e.g. 'csv', 'json', 'xlsx').
     * @param string $label  Human-readable label (e.g. 'orders', 'users').
     * @return int The new job ID.
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function enqueue(string $userId, string $format = 'csv', string $label = ''): int
    {
        $userId = $this->validateUserId($userId);
        $db     = $this->db();
        $db->prepare(
            'INSERT INTO export_jobs (user_id, format, label) VALUES (:uid, :fmt, :label)'
        )->execute([':uid' => $userId, ':fmt' => trim($format) ?: 'csv', ':label' => $label]);
        return (int)$db->lastInsertId();
    }

    /**
     * Mark a job as processing (start).
     *
     * @return bool True if the job was pending and is now processing.
     */
    public function start(int $jobId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE export_jobs
             SET status = \'processing\', started_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = \'pending\''
        );
        $stmt->execute([':id' => $jobId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a job as done.
     *
     * @param string $filename  Path or name of the generated export file.
     * @param int    $rowCount  Number of rows exported.
     * @return bool True if the job was processing and is now done.
     */
    public function complete(int $jobId, string $filename, int $rowCount = 0): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE export_jobs
             SET status = \'done\', filename = :fn, row_count = :cnt,
                 finished_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = \'processing\''
        );
        $stmt->execute([':fn' => $filename, ':cnt' => $rowCount, ':id' => $jobId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a job as failed.
     *
     * @return bool True if the job was processing and is now failed.
     */
    public function fail(int $jobId, string $error = ''): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE export_jobs
             SET status = \'failed\', error = :err, finished_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = \'processing\''
        );
        $stmt->execute([':err' => $error, ':id' => $jobId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find a job by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $jobId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, format, label, status, filename, row_count, error,
                    started_at, finished_at, created_at
             FROM export_jobs WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List jobs for a user, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(string $userId, int $limit = 20): array
    {
        $userId = $this->validateUserId($userId);
        $limit  = max(1, $limit);
        $stmt   = $this->db()->prepare(
            "SELECT id, user_id, format, label, status, filename, row_count, error,
                    started_at, finished_at, created_at
             FROM export_jobs WHERE user_id = :uid ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count jobs, optionally by status.
     *
     * @param string|null $status 'pending', 'processing', 'done', 'failed', or null for all.
     */
    public function count(?string $status = null): int
    {
        if ($status === null) {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM export_jobs');
            $stmt->execute();
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM export_jobs WHERE status = :status'
            );
            $stmt->execute([':status' => $status]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge completed/failed jobs older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM export_jobs
             WHERE created_at < :cutoff AND status IN (\'done\', \'failed\')'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }
}
