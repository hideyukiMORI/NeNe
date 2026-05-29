<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DataImportJob — CSV/data import job tracking with per-row error recording.
 *
 * Two-table design: `data_import_jobs` for the overall job lifecycle and
 * `data_import_errors` for per-row validation/processing errors.
 *
 * Distinct from BatchJob (generic progress tracking) and ExportJob (outbound).
 * This class records the full import lifecycle: pending → validating →
 * processing → done | failed.
 *
 * ## Usage
 *
 * ```php
 * $imp = new DataImportJob($pdo);
 *
 * $jobId = $imp->create('users-2026-05.csv', 'csv', 'user-admin');
 * $imp->startValidation($jobId);
 * $imp->addError($jobId, 12, 'email', 'Invalid email format');
 * $imp->startProcessing($jobId, 500);
 * $imp->finish($jobId, 490);  // 10 rows skipped/errored
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE data_import_jobs (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     filename     TEXT         NOT NULL,
 *     format       VARCHAR(20)  NOT NULL DEFAULT 'csv',
 *     uploaded_by  VARCHAR(255) NOT NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     total_rows   INTEGER      NULL,
 *     done_rows    INTEGER      NOT NULL DEFAULT 0,
 *     error_count  INTEGER      NOT NULL DEFAULT 0,
 *     started_at   DATETIME     NULL,
 *     finished_at  DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE data_import_errors (
 *     id       INTEGER PRIMARY KEY AUTOINCREMENT,
 *     job_id   INTEGER      NOT NULL,
 *     row_num  INTEGER      NOT NULL,
 *     field    VARCHAR(100) NULL,
 *     message  TEXT         NOT NULL,
 *     created_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class DataImportJob
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new import job.
     *
     * @return int Job ID.
     * @throws \InvalidArgumentException on empty filename or uploadedBy.
     */
    public function create(string $filename, string $format, string $uploadedBy): int
    {
        $filename   = trim($filename);
        $uploadedBy = trim($uploadedBy);
        if ($filename === '') {
            throw new \InvalidArgumentException('filename must not be empty.');
        }
        if ($uploadedBy === '') {
            throw new \InvalidArgumentException('uploaded_by must not be empty.');
        }
        $format = trim($format) === '' ? 'csv' : trim($format);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'INSERT INTO data_import_jobs (filename, format, uploaded_by, created_at)
             VALUES (:file, :fmt, :uid, :now)'
        );
        $stmt->execute([':file' => $filename, ':fmt' => $format, ':uid' => $uploadedBy, ':now' => $now]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a job by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, filename, format, uploaded_by, status,
                    total_rows, done_rows, error_count, started_at, finished_at, created_at
             FROM data_import_jobs WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Transition job to validating status.
     *
     * @return bool True if found and updated.
     */
    public function startValidation(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE data_import_jobs SET status = 'validating', started_at = :now
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Transition job to processing status and record total row count.
     *
     * @return bool True if found and updated.
     */
    public function startProcessing(int $id, int $totalRows): bool
    {
        $stmt = $this->db()->prepare(
            "UPDATE data_import_jobs SET status = 'processing', total_rows = :total
             WHERE id = :id AND status IN ('pending', 'validating')"
        );
        $stmt->execute([':total' => $totalRows, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark job as done and record successfully processed row count.
     *
     * @return bool True if found and updated.
     */
    public function finish(int $id, int $doneRows): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE data_import_jobs SET status = 'done', done_rows = :done, finished_at = :now
             WHERE id = :id AND status = 'processing'"
        );
        $stmt->execute([':done' => $doneRows, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark job as failed with an optional reason message.
     *
     * @return bool True if found and updated.
     */
    public function fail(int $id, ?string $reason = null): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE data_import_jobs
             SET status = 'failed', finished_at = :now
             WHERE id = :id AND status NOT IN ('done', 'failed')"
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        if ($reason !== null) {
            $this->addError($id, 0, null, $reason);
        }
        return true;
    }

    /**
     * Record a per-row validation/processing error.
     *
     * @return int Error row ID.
     */
    public function addError(int $jobId, int $rowNum, ?string $field, string $message): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO data_import_errors (job_id, row_num, field, message, created_at)
             VALUES (:job, :row, :field, :msg, :now)'
        );
        $stmt->execute([
            ':job'   => $jobId,
            ':row'   => $rowNum,
            ':field' => $field,
            ':msg'   => $message,
            ':now'   => $now,
        ]);
        // Increment error_count on job
        $this->db()->prepare(
            'UPDATE data_import_jobs SET error_count = error_count + 1 WHERE id = :id'
        )->execute([':id' => $jobId]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return all errors for a job, ordered by row number.
     *
     * @return list<array<string,mixed>>
     */
    public function errors(int $jobId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, job_id, row_num, field, message, created_at
             FROM data_import_errors WHERE job_id = :job ORDER BY row_num ASC, id ASC'
        );
        $stmt->execute([':job' => $jobId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return jobs for a user, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $uploadedBy): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, filename, format, uploaded_by, status,
                    total_rows, done_rows, error_count, started_at, finished_at, created_at
             FROM data_import_jobs WHERE uploaded_by = :uid ORDER BY id DESC'
        );
        $stmt->execute([':uid' => trim($uploadedBy)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
