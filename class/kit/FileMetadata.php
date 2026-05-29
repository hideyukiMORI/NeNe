<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * File storage metadata management.
 *
 * Records file metadata (path, size, MIME type, owner) in the database
 * without managing the actual file storage. The physical file lifecycle
 * (upload, delete from disk/S3/etc.) is handled by the application.
 *
 * Supports soft delete: files are marked deleted_at but the row is retained
 * for audit purposes.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE file_metadata (
 *     id           INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     owner_id     VARCHAR(255) NOT NULL,
 *     path         VARCHAR(1024) NOT NULL,
 *     filename     VARCHAR(255) NOT NULL,
 *     mime_type    VARCHAR(127) NOT NULL,
 *     size_bytes   INTEGER      NOT NULL DEFAULT 0,
 *     storage      VARCHAR(64)  NOT NULL DEFAULT 'local',
 *     deleted_at   DATETIME     DEFAULT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_file_metadata_owner ON file_metadata (owner_id, deleted_at);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $fm = new FileMetadata($pdo);
 *
 * // Register after upload
 * $id = $fm->register('user:1', '/uploads/abc123.jpg', 'photo.jpg', 'image/jpeg', 204800);
 *
 * // Find
 * $file = $fm->find($id);
 *
 * // List active files for owner
 * $fm->findByOwner('user:1');
 *
 * // Soft delete (mark deleted, retain row)
 * $fm->delete($id, 'user:1');
 * ```
 */
final class FileMetadata
{
    private const TABLE = 'file_metadata';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Register file metadata after an upload.
     *
     * @param  string $ownerId    Application-level owner identifier.
     * @param  string $path       Storage path or URL (e.g. '/uploads/abc123.jpg', 's3://bucket/key').
     * @param  string $filename   Original filename as uploaded by the user.
     * @param  string $mimeType   MIME type (e.g. 'image/jpeg').
     * @param  int    $sizeBytes  File size in bytes.
     * @param  string $storage    Storage backend identifier (e.g. 'local', 's3', 'gcs').
     * @return int New metadata row ID.
     */
    public function register(
        string $ownerId,
        string $path,
        string $filename,
        string $mimeType,
        int $sizeBytes = 0,
        string $storage = 'local',
    ): int {
        $db        = $this->db ?? PdoConnection::getInstance();
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (owner_id, path, filename, mime_type, size_bytes, storage, created_at)' .
            ' VALUES (:owner, :path, :filename, :mime, :size, :storage, :t)'
        );
        $stmt->bindValue(':owner', $ownerId, PDO::PARAM_STR);
        $stmt->bindValue(':path', $path, PDO::PARAM_STR);
        $stmt->bindValue(':filename', $filename, PDO::PARAM_STR);
        $stmt->bindValue(':mime', $mimeType, PDO::PARAM_STR);
        $stmt->bindValue(':size', $sizeBytes, PDO::PARAM_INT);
        $stmt->bindValue(':storage', $storage, PDO::PARAM_STR);
        $stmt->bindValue(':t', $createdAt, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$db->lastInsertId();
    }

    /**
     * Find a file by ID. Returns null if not found.
     *
     * @param  int $id Row ID.
     * @return array{id:int,owner_id:string,path:string,filename:string,mime_type:string,size_bytes:int,storage:string,deleted_at:string|null,created_at:string}|null
     */
    public function find(int $id): ?array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, owner_id, path, filename, mime_type, size_bytes, storage, deleted_at, created_at' .
            ' FROM ' . $this->table . ' WHERE id = :id LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * List active (non-deleted) files for an owner.
     *
     * @param  string      $ownerId   Owner identifier.
     * @param  string|null $mimeType  Filter by MIME type prefix (e.g. 'image/'). Null = all.
     * @return list<array{id:int,owner_id:string,path:string,filename:string,mime_type:string,size_bytes:int,storage:string,deleted_at:null,created_at:string}>
     */
    public function findByOwner(string $ownerId, ?string $mimeType = null): array
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $sql = 'SELECT id, owner_id, path, filename, mime_type, size_bytes, storage, deleted_at, created_at' .
               ' FROM ' . $this->table .
               ' WHERE owner_id = :owner AND deleted_at IS NULL';

        if ($mimeType !== null) {
            $sql .= ' AND mime_type LIKE :mime';
        }

        $sql .= ' ORDER BY id DESC';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':owner', $ownerId, PDO::PARAM_STR);
        if ($mimeType !== null) {
            $prefix = rtrim($mimeType, '%') . '%';
            $stmt->bindValue(':mime', $prefix, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => $this->mapRow($r), $rows);
    }

    /**
     * Soft-delete a file. Only the owner can delete their own file.
     *
     * Returns true if deleted, false if not found or wrong owner.
     *
     * @param int    $id      Row ID.
     * @param string $ownerId Must match the owner_id used in register().
     */
    public function delete(int $id, string $ownerId): bool
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $deletedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET deleted_at = :t WHERE id = :id AND owner_id = :owner AND deleted_at IS NULL'
        );
        $stmt->bindValue(':t', $deletedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':owner', $ownerId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed> $row
     * @return array{id:int,owner_id:string,path:string,filename:string,mime_type:string,size_bytes:int,storage:string,deleted_at:string|null,created_at:string}
     */
    private function mapRow(array $row): array
    {
        return [
            'id'         => (int)$row['id'],
            'owner_id'   => (string)$row['owner_id'],
            'path'       => (string)$row['path'],
            'filename'   => (string)$row['filename'],
            'mime_type'  => (string)$row['mime_type'],
            'size_bytes' => (int)$row['size_bytes'],
            'storage'    => (string)$row['storage'],
            'deleted_at' => $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }
}
