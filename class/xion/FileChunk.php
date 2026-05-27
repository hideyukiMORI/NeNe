<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * FileChunk — chunked file upload tracking and reassembly coordination.
 *
 * Tracks the state of multi-chunk file uploads. The caller uploads each chunk
 * and marks it received; once all chunks are received, the upload is complete
 * and the chunks can be assembled.
 *
 * This class handles state tracking only. Actual chunk storage and
 * file assembly are the caller's responsibility.
 *
 * ## Usage
 *
 * ```php
 * $fc = new FileChunk($pdo);
 *
 * // Start an upload (called by client before first chunk)
 * $uploadId = $fc->start('user-1', 'document.pdf', 1024000, 10); // 10 chunks
 *
 * // Mark each chunk received (called after storing chunk to disk/S3)
 * $fc->received($uploadId, 0); // chunk index 0
 * $fc->received($uploadId, 1);
 * // ...
 *
 * // Check completion
 * if ($fc->isComplete($uploadId)) {
 *     $upload = $fc->find($uploadId);
 *     // assemble chunks into final file
 *     $fc->markDone($uploadId);
 * }
 *
 * // Stale cleanup
 * $fc->purgeOlderThan(24); // hours
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE file_chunk_uploads (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     upload_id    VARCHAR(36)  NOT NULL UNIQUE,
 *     user_id      VARCHAR(255) NOT NULL DEFAULT '',
 *     filename     VARCHAR(500) NOT NULL DEFAULT '',
 *     total_size   BIGINT       NOT NULL DEFAULT 0,
 *     total_chunks INT          NOT NULL DEFAULT 1,
 *     received     INT          NOT NULL DEFAULT 0,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'uploading',
 *     started_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class FileChunk
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Start a new chunked upload.
     *
     * @param  string $userId       The uploading user (optional).
     * @param  string $filename     Original file name.
     * @param  int    $totalSize    Total file size in bytes.
     * @param  int    $totalChunks  Total number of chunks expected.
     * @return string  The upload_id (UUID-like random hex).
     * @throws \InvalidArgumentException if totalChunks < 1.
     */
    public function start(
        string $userId = '',
        string $filename = '',
        int $totalSize = 0,
        int $totalChunks = 1
    ): string {
        if ($totalChunks < 1) {
            throw new \InvalidArgumentException('totalChunks must be at least 1.');
        }

        $uploadId = sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );

        $this->db()->prepare(
            'INSERT INTO file_chunk_uploads (upload_id, user_id, filename, total_size, total_chunks)
             VALUES (:uid, :user, :file, :size, :chunks)'
        )->execute([
            ':uid'    => $uploadId,
            ':user'   => $userId,
            ':file'   => $filename,
            ':size'   => max(0, $totalSize),
            ':chunks' => $totalChunks,
        ]);

        return $uploadId;
    }

    /**
     * Mark a chunk as received.
     *
     * Increments the received counter. The chunk index is informational only.
     *
     * @return bool True if the upload exists and is still uploading.
     */
    public function received(string $uploadId, int $chunkIndex = 0): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE file_chunk_uploads
             SET received = received + 1, updated_at = :now
             WHERE upload_id = :uid AND status = 'uploading'"
        );
        $stmt->execute([':now' => $now, ':uid' => $uploadId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether all chunks have been received.
     */
    public function isComplete(string $uploadId): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT received >= total_chunks FROM file_chunk_uploads WHERE upload_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $uploadId]);
        $val = $stmt->fetchColumn();
        return $val !== false && (int)$val === 1;
    }

    /**
     * Mark the upload as done (assembly complete).
     */
    public function markDone(string $uploadId): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE file_chunk_uploads SET status = 'done', updated_at = :now WHERE upload_id = :uid"
        );
        $stmt->execute([':now' => $now, ':uid' => $uploadId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark the upload as failed.
     */
    public function markFailed(string $uploadId): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            "UPDATE file_chunk_uploads SET status = 'failed', updated_at = :now WHERE upload_id = :uid"
        );
        $stmt->execute([':now' => $now, ':uid' => $uploadId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find an upload record by upload_id.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $uploadId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, upload_id, user_id, filename, total_size, total_chunks, received, status, started_at, updated_at
             FROM file_chunk_uploads WHERE upload_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $uploadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List incomplete uploads for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingForUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            "SELECT id, upload_id, filename, total_chunks, received, status, started_at
             FROM file_chunk_uploads WHERE user_id = :uid AND status = 'uploading'
             ORDER BY id DESC"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete uploads older than N hours (stale cleanup).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $hours): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$hours} hours")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM file_chunk_uploads WHERE started_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
