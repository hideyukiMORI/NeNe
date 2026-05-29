<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * MediaConversionJob — async media processing job tracking.
 *
 * Tracks background jobs that convert or transcode media files
 * (image resize, video transcode, audio encode, PDF generation, etc.).
 * A worker picks up PENDING jobs, marks them IN_PROGRESS, then either
 * completes or fails them.
 *
 * ## Usage
 *
 * ```php
 * $mc = new MediaConversionJob($pdo);
 *
 * // Enqueue a conversion
 * $id = $mc->enqueue('image', '/uploads/photo.jpg', 'webp', ['width' => 800]);
 *
 * // Worker loop
 * $jobs = $mc->nextPending(5);
 * foreach ($jobs as $job) {
 *     $mc->start($job['id']);
 *     try {
 *         $output = convertFile($job);
 *         $mc->complete($job['id'], $output);
 *     } catch (\Throwable $e) {
 *         $mc->fail($job['id'], $e->getMessage());
 *     }
 * }
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE media_conversion_jobs (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     media_type   VARCHAR(50)  NOT NULL,
 *     source_path  TEXT         NOT NULL,
 *     output_format VARCHAR(50) NOT NULL,
 *     options      TEXT         NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     output_path  TEXT         NULL,
 *     error        TEXT         NULL,
 *     attempts     INTEGER      NOT NULL DEFAULT 0,
 *     started_at   DATETIME     NULL,
 *     completed_at DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class MediaConversionJob
{
    public const STATUS_PENDING     = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_FAILED      = 'failed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Enqueue a new media conversion job.
     *
     * @param array<string,mixed>|null $options Conversion options (e.g. dimensions, quality).
     * @return int New job ID.
     * @throws \InvalidArgumentException on empty mediaType/sourcePath/outputFormat.
     */
    public function enqueue(
        string $mediaType,
        string $sourcePath,
        string $outputFormat,
        ?array $options = null
    ): int {
        $mediaType    = trim($mediaType);
        $sourcePath   = trim($sourcePath);
        $outputFormat = trim($outputFormat);
        if ($mediaType === '') {
            throw new \InvalidArgumentException('media_type must not be empty.');
        }
        if ($sourcePath === '') {
            throw new \InvalidArgumentException('source_path must not be empty.');
        }
        if ($outputFormat === '') {
            throw new \InvalidArgumentException('output_format must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO media_conversion_jobs
                 (media_type, source_path, output_format, options, status, created_at)
             VALUES (:type, :src, :fmt, :opts, :status, :now)'
        );
        $stmt->execute([
            ':type'   => $mediaType,
            ':src'    => $sourcePath,
            ':fmt'    => $outputFormat,
            ':opts'   => $options !== null ? json_encode($options, JSON_UNESCAPED_UNICODE) : null,
            ':status' => self::STATUS_PENDING,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a job by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM media_conversion_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch the next N pending jobs, ordered by creation time (FIFO).
     *
     * @return list<array<string,mixed>>
     */
    public function nextPending(int $limit = 10): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM media_conversion_jobs
             WHERE status = :status
             ORDER BY created_at ASC, id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':status', self::STATUS_PENDING);
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark a job as in-progress and increment the attempt counter.
     *
     * @return bool True if found and updated.
     */
    public function start(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE media_conversion_jobs
             SET status = :status, started_at = :now, attempts = attempts + 1
             WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_IN_PROGRESS, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a job as completed with the output file path.
     *
     * @return bool True if found and updated.
     */
    public function complete(int $id, string $outputPath): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE media_conversion_jobs
             SET status = :status, output_path = :out, completed_at = :now
             WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_COMPLETED, ':out' => $outputPath, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a job as failed with an error message.
     *
     * @return bool True if found and updated.
     */
    public function fail(int $id, string $error): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE media_conversion_jobs SET status = :status, error = :error WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_FAILED, ':error' => $error, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reset a failed job back to pending for retry.
     *
     * @return bool True if found and reset.
     */
    public function retry(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE media_conversion_jobs
             SET status = :status, error = NULL, started_at = NULL
             WHERE id = :id AND status = :failed'
        );
        $stmt->execute([
            ':status' => self::STATUS_PENDING,
            ':id'     => $id,
            ':failed' => self::STATUS_FAILED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return jobs by status, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function byStatus(string $status, int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM media_conversion_jobs
             WHERE status = :status
             ORDER BY created_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete completed/failed jobs older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM media_conversion_jobs
             WHERE status IN (:completed, :failed) AND created_at < :cutoff'
        );
        $stmt->execute([
            ':completed' => self::STATUS_COMPLETED,
            ':failed'    => self::STATUS_FAILED,
            ':cutoff'    => $cutoff,
        ]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
