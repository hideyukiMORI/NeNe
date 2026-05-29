<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Attachment — file attachments linked to any entity.
 *
 * Stores metadata about uploaded files attached to arbitrary resources
 * (issues, comments, orders, etc.). The physical file storage (disk, S3)
 * is outside scope; this class tracks the association, size, MIME type,
 * original filename, and an optional label.
 *
 * ## Usage
 *
 * ```php
 * $att = new Attachment($pdo);
 *
 * // Attach a file
 * $id = $att->attach('issue', '42', 'user-1', [
 *     'filename'     => 'screenshot.png',
 *     'storage_key'  => 'uploads/2026/05/abc123.png',
 *     'mime_type'    => 'image/png',
 *     'size_bytes'   => 204800,
 *     'label'        => 'Bug screenshot',
 * ]);
 *
 * // List / delete
 * $files = $att->forEntity('issue', '42');
 * $att->detach($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE attachments (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     uploaded_by  VARCHAR(255) NOT NULL DEFAULT '',
 *     filename     VARCHAR(255) NOT NULL,
 *     storage_key  VARCHAR(500) NOT NULL,
 *     mime_type    VARCHAR(127) NOT NULL DEFAULT '',
 *     size_bytes   BIGINT       NOT NULL DEFAULT 0,
 *     label        VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class Attachment
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Attach a file to an entity.
     *
     * @param  array{
     *   filename: string,
     *   storage_key: string,
     *   mime_type?: string,
     *   size_bytes?: int,
     *   label?: string,
     * } $file
     * @return int Row ID of the new attachment.
     * @throws \InvalidArgumentException on missing required fields.
     */
    public function attach(
        string $entityType,
        string $entityId,
        string $uploadedBy,
        array $file
    ): int {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);

        $filename   = trim($file['filename'] ?? '');
        $storageKey = trim($file['storage_key'] ?? '');
        if ($filename === '') {
            throw new \InvalidArgumentException('filename must not be empty.');
        }
        if ($storageKey === '') {
            throw new \InvalidArgumentException('storage_key must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO attachments
                 (entity_type, entity_id, uploaded_by, filename, storage_key, mime_type, size_bytes, label)
             VALUES (:type, :eid, :by, :fname, :skey, :mime, :size, :label)'
        );
        $stmt->execute([
            ':type'  => $entityType,
            ':eid'   => $entityId,
            ':by'    => trim($uploadedBy),
            ':fname' => $filename,
            ':skey'  => $storageKey,
            ':mime'  => trim($file['mime_type'] ?? ''),
            ':size'  => (int)($file['size_bytes'] ?? 0),
            ':label' => trim($file['label'] ?? ''),
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Remove an attachment by ID.
     *
     * @return bool True if a row was deleted.
     */
    public function detach(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM attachments WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove all attachments for an entity.
     *
     * @return int Number of rows deleted.
     */
    public function detachAll(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM attachments WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount();
    }

    /**
     * Find a single attachment by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM attachments WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all attachments for an entity (oldest first).
     *
     * @return list<array<string,mixed>>
     */
    public function forEntity(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, uploaded_by, filename, storage_key,
                    mime_type, size_bytes, label, created_at
             FROM attachments
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all attachments uploaded by a user.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $uploadedBy, int $limit = 50): array
    {
        $uploadedBy = trim($uploadedBy);
        if ($uploadedBy === '') {
            throw new \InvalidArgumentException('uploaded_by must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, uploaded_by, filename, storage_key,
                    mime_type, size_bytes, label, created_at
             FROM attachments
             WHERE uploaded_by = :by
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':by', $uploadedBy);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count attachments for an entity.
     */
    public function count(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM attachments WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Total size in bytes of attachments for an entity.
     */
    public function totalBytes(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(size_bytes), 0)
             FROM attachments WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Update the label of an attachment.
     *
     * @return bool True if found and updated.
     */
    public function updateLabel(int $id, string $label): bool
    {
        $stmt = $this->db()->prepare('UPDATE attachments SET label = :label WHERE id = :id');
        $stmt->execute([':label' => trim($label), ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function validateEntity(string $entityType, string $entityId): array
    {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        return [$entityType, $entityId];
    }
}
