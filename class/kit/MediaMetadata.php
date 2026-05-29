<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * MediaMetadata — store and retrieve metadata for uploaded media files.
 *
 * Tracks file attributes (MIME type, size, dimensions) and arbitrary
 * key-value metadata (alt text, caption, credits, etc.) per file.
 *
 * ## Usage
 *
 * ```php
 * $mm = new MediaMetadata($pdo);
 *
 * // Register a file
 * $id = $mm->register('file-uuid-1', 'image/jpeg', 204800, ['width' => 1920, 'height' => 1080]);
 *
 * // Set metadata
 * $mm->setMeta($id, 'alt_text', 'A scenic mountain photo');
 *
 * // Retrieve
 * $mm->find($id);
 * $mm->getMeta($id, 'alt_text');
 * $mm->allMeta($id);
 *
 * // Delete
 * $mm->remove($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE media_files (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     file_key    VARCHAR(255) NOT NULL UNIQUE,
 *     mime_type   VARCHAR(100) NOT NULL DEFAULT '',
 *     file_size   BIGINT       NOT NULL DEFAULT 0,
 *     attributes  TEXT         NOT NULL DEFAULT '{}',
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE media_meta (
 *     file_id    INTEGER      NOT NULL,
 *     meta_key   VARCHAR(100) NOT NULL,
 *     meta_value TEXT         NOT NULL DEFAULT '',
 *     PRIMARY KEY (file_id, meta_key)
 * );
 * ```
 */
final class MediaMetadata
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Register a media file.
     *
     * @param  array<string,mixed> $attributes Extra attributes (width, height, duration…)
     * @return int The new file record ID.
     * @throws \InvalidArgumentException if file_key is empty.
     * @throws \RuntimeException if file_key is already registered.
     */
    public function register(
        string $fileKey,
        string $mimeType = '',
        int $fileSize = 0,
        array $attributes = []
    ): int {
        $fileKey = $this->validateFileKey($fileKey);
        $db      = $this->db();

        $db->prepare(
            'INSERT INTO media_files (file_key, mime_type, file_size, attributes)
             VALUES (:key, :mime, :size, :attrs)'
        )->execute([
            ':key'   => $fileKey,
            ':mime'  => $mimeType,
            ':size'  => $fileSize,
            ':attrs' => json_encode($attributes, JSON_THROW_ON_ERROR),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Find a media file by its database ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, file_key, mime_type, file_size, attributes, created_at
             FROM media_files WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['attributes'] = json_decode((string)$row['attributes'], true) ?? [];
        return $row;
    }

    /**
     * Find a media file by its file key.
     *
     * @return array<string,mixed>|null
     */
    public function findByKey(string $fileKey): ?array
    {
        $fileKey = $this->validateFileKey($fileKey);
        $stmt    = $this->db()->prepare(
            'SELECT id, file_key, mime_type, file_size, attributes, created_at
             FROM media_files WHERE file_key = :key LIMIT 1'
        );
        $stmt->execute([':key' => $fileKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['attributes'] = json_decode((string)$row['attributes'], true) ?? [];
        return $row;
    }

    /**
     * Set (upsert) a metadata key-value pair for a file.
     */
    public function setMeta(int $fileId, string $key, string $value): void
    {
        $key = $this->validateMetaKey($key);
        $db  = $this->db();

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO media_meta (file_id, meta_key, meta_value)
                 VALUES (:fid, :key, :val)
                 ON CONFLICT (file_id, meta_key)
                 DO UPDATE SET meta_value = excluded.meta_value'
            )->execute([':fid' => $fileId, ':key' => $key, ':val' => $value]);
        } else {
            $db->prepare(
                'INSERT INTO media_meta (file_id, meta_key, meta_value)
                 VALUES (:fid, :key, :val)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)'
            )->execute([':fid' => $fileId, ':key' => $key, ':val' => $value]);
        }
    }

    /**
     * Get a single metadata value (with default).
     */
    public function getMeta(int $fileId, string $key, string $default = ''): string
    {
        $key  = $this->validateMetaKey($key);
        $stmt = $this->db()->prepare(
            'SELECT meta_value FROM media_meta WHERE file_id = :fid AND meta_key = :key LIMIT 1'
        );
        $stmt->execute([':fid' => $fileId, ':key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $default;
    }

    /**
     * Get all metadata for a file as key-value map.
     *
     * @return array<string,string>
     */
    public function allMeta(int $fileId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT meta_key, meta_value FROM media_meta WHERE file_id = :fid ORDER BY meta_key ASC'
        );
        $stmt->execute([':fid' => $fileId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string)$row['meta_key']] = (string)$row['meta_value'];
        }
        return $result;
    }

    /**
     * Delete a single metadata key.
     *
     * @return bool True if a row was deleted.
     */
    public function deleteMeta(int $fileId, string $key): bool
    {
        $key  = $this->validateMetaKey($key);
        $stmt = $this->db()->prepare(
            'DELETE FROM media_meta WHERE file_id = :fid AND meta_key = :key'
        );
        $stmt->execute([':fid' => $fileId, ':key' => $key]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a media file and all its metadata (hard delete).
     *
     * @return bool True if the file was found and deleted.
     */
    public function remove(int $id): bool
    {
        $db = $this->db();
        $db->prepare('DELETE FROM media_meta WHERE file_id = :id')->execute([':id' => $id]);
        $stmt = $db->prepare('DELETE FROM media_files WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Count all registered media files.
     */
    public function count(): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM media_files');
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateFileKey(string $fileKey): string
    {
        $fileKey = trim($fileKey);
        if ($fileKey === '') {
            throw new \InvalidArgumentException('file_key must not be empty.');
        }
        return $fileKey;
    }

    private function validateMetaKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('meta_key must not be empty.');
        }
        return $key;
    }
}
