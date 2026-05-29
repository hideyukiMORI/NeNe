<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * CronLog — scheduled task execution log.
 *
 * Tracks cron job runs with start/finish/fail lifecycle, output capture,
 * and execution time. Useful for monitoring, alerting, and debugging
 * scheduled tasks.
 *
 * ## Usage
 *
 * ```php
 * $log = new CronLog($pdo);
 *
 * // Start a run
 * $runId = $log->start('cleanup:old-files');
 *
 * // ... do work ...
 *
 * // Mark as finished (success)
 * $log->finish($runId, 'Deleted 42 files.');
 *
 * // or mark as failed
 * $log->fail($runId, 'Permission denied on /tmp/uploads');
 *
 * // Query history
 * $log->recent('cleanup:old-files', 20);
 * $log->lastSuccess('cleanup:old-files');
 * $log->count('cleanup:old-files');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE cron_log (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     job_name    VARCHAR(255) NOT NULL,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'running',
 *     output      TEXT         NOT NULL DEFAULT '',
 *     started_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     finished_at DATETIME     DEFAULT NULL
 * );
 * ```
 */
final class CronLog
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record the start of a cron job run.
     *
     * @return int The run log ID (pass to finish() or fail()).
     * @throws \InvalidArgumentException if job_name is empty.
     */
    public function start(string $jobName): int
    {
        $jobName = $this->validateJobName($jobName);
        $db      = $this->db();
        $db->prepare(
            'INSERT INTO cron_log (job_name, status) VALUES (:name, \'running\')'
        )->execute([':name' => $jobName]);
        return (int)$db->lastInsertId();
    }

    /**
     * Mark a run as successfully finished.
     *
     * @return bool True if the run existed and was updated.
     */
    public function finish(int $runId, string $output = ''): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE cron_log
             SET status = \'finished\', output = :out, finished_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = \'running\''
        );
        $stmt->execute([':out' => $output, ':id' => $runId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a run as failed.
     *
     * @return bool True if the run existed and was updated.
     */
    public function fail(int $runId, string $output = ''): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE cron_log
             SET status = \'failed\', output = :out, finished_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = \'running\''
        );
        $stmt->execute([':out' => $output, ':id' => $runId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find a run by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $runId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, job_name, status, output, started_at, finished_at
             FROM cron_log WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the most recent runs for a job, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(string $jobName, int $limit = 20): array
    {
        $jobName = $this->validateJobName($jobName);
        $limit   = max(1, $limit);
        $stmt    = $this->db()->prepare(
            "SELECT id, job_name, status, output, started_at, finished_at
             FROM cron_log
             WHERE job_name = :name
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':name' => $jobName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get the most recent successful run for a job.
     *
     * @return array<string,mixed>|null
     */
    public function lastSuccess(string $jobName): ?array
    {
        $jobName = $this->validateJobName($jobName);
        $stmt    = $this->db()->prepare(
            'SELECT id, job_name, status, output, started_at, finished_at
             FROM cron_log
             WHERE job_name = :name AND status = \'finished\'
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':name' => $jobName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the most recent failed run for a job.
     *
     * @return array<string,mixed>|null
     */
    public function lastFailure(string $jobName): ?array
    {
        $jobName = $this->validateJobName($jobName);
        $stmt    = $this->db()->prepare(
            'SELECT id, job_name, status, output, started_at, finished_at
             FROM cron_log
             WHERE job_name = :name AND status = \'failed\'
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':name' => $jobName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Count runs for a job, optionally filtered by status.
     *
     * @param string|null $status 'running', 'finished', 'failed', or null for all.
     */
    public function count(string $jobName, ?string $status = null): int
    {
        $jobName = $this->validateJobName($jobName);
        if ($status === null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM cron_log WHERE job_name = :name'
            );
            $stmt->execute([':name' => $jobName]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM cron_log WHERE job_name = :name AND status = :status'
            );
            $stmt->execute([':name' => $jobName, ':status' => $status]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge run records older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM cron_log WHERE started_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateJobName(string $jobName): string
    {
        $jobName = trim($jobName);
        if ($jobName === '') {
            throw new \InvalidArgumentException('job_name must not be empty.');
        }
        return $jobName;
    }
}
