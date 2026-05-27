<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * MediaGallery — ordered media item gallery attached to any entity.
 *
 * Manages ordered image/media galleries per entity with explicit position,
 * captions, and a cover item designation. Physical storage is outside scope;
 * this class records only metadata (storage key + caption + position).
 *
 * Distinct from Attachment (unordered, generic files). Gallery items have
 * deliberate ordering and a single cover image per entity.
 *
 * ## Usage
 *
 * ```php
 * $gallery = new MediaGallery($pdo);
 *
 * $id1 = $gallery->addItem('post', '10', 's3://bucket/a.jpg', 'Main photo', 1);
 * $id2 = $gallery->addItem('post', '10', 's3://bucket/b.jpg', 'Side view', 2);
 *
 * $gallery->setCover($id1);
 * $cover = $gallery->getCover('post', '10');
 * $items = $gallery->items('post', '10');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE gallery_items (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     storage_key  TEXT         NOT NULL,
 *     caption      TEXT         NULL,
 *     position     INTEGER      NOT NULL DEFAULT 0,
 *     is_cover     TINYINT(1)   NOT NULL DEFAULT 0,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class MediaGallery
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a media item to the gallery.
     *
     * @return int Row ID of the new item.
     * @throws \InvalidArgumentException on empty entity type/id or storage key.
     */
    public function addItem(
        string $entityType,
        string $entityId,
        string $storageKey,
        ?string $caption = null,
        int $position = 0
    ): int {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $storageKey = trim($storageKey);
        if ($storageKey === '') {
            throw new \InvalidArgumentException('storage_key must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO gallery_items (entity_type, entity_id, storage_key, caption, position)
             VALUES (:type, :eid, :key, :caption, :pos)'
        );
        $stmt->execute([
            ':type'    => $entityType,
            ':eid'     => $entityId,
            ':key'     => $storageKey,
            ':caption' => $caption,
            ':pos'     => $position,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Remove a gallery item.
     * If the removed item was the cover, no other item is auto-promoted.
     *
     * @return bool True if found and deleted.
     */
    public function removeItem(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM gallery_items WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Set an item as the cover for its entity; clears the previous cover.
     *
     * @return bool True if the item was found and updated.
     */
    public function setCover(int $id): bool
    {
        $row = $this->findRow($id);
        if ($row === null) {
            return false;
        }

        // Clear all covers for this entity, then set the new one.
        $clearStmt = $this->db()->prepare(
            'UPDATE gallery_items SET is_cover = 0
             WHERE entity_type = :type AND entity_id = :eid'
        );
        $clearStmt->execute([':type' => $row['entity_type'], ':eid' => $row['entity_id']]);

        $setStmt = $this->db()->prepare('UPDATE gallery_items SET is_cover = 1 WHERE id = :id');
        $setStmt->execute([':id' => $id]);
        return $setStmt->rowCount() > 0;
    }

    /**
     * Return the current cover item for an entity, or null if none.
     *
     * @return array<string,mixed>|null
     */
    public function getCover(string $entityType, string $entityId): ?array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, storage_key, caption, position, is_cover, created_at
             FROM gallery_items
             WHERE entity_type = :type AND entity_id = :eid AND is_cover = 1
             LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Update the caption of an item.
     *
     * @return bool True if found and updated.
     */
    public function updateCaption(int $id, ?string $caption): bool
    {
        $stmt = $this->db()->prepare('UPDATE gallery_items SET caption = :caption WHERE id = :id');
        $stmt->execute([':caption' => $caption, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Change the display position of an item.
     *
     * @return bool True if found and updated.
     */
    public function reorder(int $id, int $position): bool
    {
        $stmt = $this->db()->prepare('UPDATE gallery_items SET position = :pos WHERE id = :id');
        $stmt->execute([':pos' => $position, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all gallery items for an entity, ordered by position then id.
     *
     * @return list<array<string,mixed>>
     */
    public function items(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, storage_key, caption, position, is_cover, created_at
             FROM gallery_items
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete all gallery items for an entity.
     *
     * @return int Number of rows deleted.
     */
    public function clear(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM gallery_items WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount();
    }

    /**
     * Return the number of gallery items for an entity.
     */
    public function count(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM gallery_items WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRow(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, entity_type, entity_id FROM gallery_items WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
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
