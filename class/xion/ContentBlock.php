<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ContentBlock — ordered content blocks for page builder / CMS layouts.
 *
 * Stores typed, ordered content blocks attached to a page or any entity.
 * Each block has a type (text, image, video, cta, …), an explicit position,
 * and a JSON content payload. Blocks can be reordered by updating positions.
 * Inactive blocks are retained but excluded from the default list.
 *
 * ## Usage
 *
 * ```php
 * $cb = new ContentBlock($pdo);
 *
 * $id1 = $cb->add('page', 'home', 'hero',    ['title' => 'Welcome'], 1);
 * $id2 = $cb->add('page', 'home', 'text',    ['body'  => 'Hello…'],  2);
 * $id3 = $cb->add('page', 'home', 'cta',     ['url'   => '/signup'], 3);
 *
 * $blocks = $cb->blocks('page', 'home');
 *
 * $cb->update($id2, ['body' => 'Updated!']);
 * $cb->reorder($id3, 1);  // move CTA to top
 * $cb->deactivate($id2);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE content_blocks (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     block_type  VARCHAR(100) NOT NULL,
 *     content     TEXT         NOT NULL,
 *     position    INTEGER      NOT NULL DEFAULT 0,
 *     active      TINYINT(1)   NOT NULL DEFAULT 1,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ContentBlock
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a content block to an entity.
     *
     * @param array<string,mixed> $content JSON-serialisable content payload.
     * @return int New block ID.
     * @throws \InvalidArgumentException on empty entityType/entityId/blockType or empty content.
     */
    public function add(
        string $entityType,
        string $entityId,
        string $blockType,
        array $content,
        int $position = 0
    ): int {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $blockType = trim($blockType);
        if ($blockType === '') {
            throw new \InvalidArgumentException('block_type must not be empty.');
        }
        if (empty($content)) {
            throw new \InvalidArgumentException('content must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO content_blocks (entity_type, entity_id, block_type, content, position, active, created_at, updated_at)
             VALUES (:type, :eid, :btype, :content, :pos, 1, :now, :now)'
        );
        $stmt->execute([
            ':type'    => $entityType,
            ':eid'     => $entityId,
            ':btype'   => $blockType,
            ':content' => json_encode($content, JSON_UNESCAPED_UNICODE),
            ':pos'     => $position,
            ':now'     => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return a single block row.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM content_blocks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return active blocks for an entity, ordered by position then id.
     *
     * @return list<array<string,mixed>>
     */
    public function blocks(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, block_type, content, position, active, created_at, updated_at
             FROM content_blocks
             WHERE entity_type = :type AND entity_id = :eid AND active = 1
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return all blocks for an entity (active and inactive).
     *
     * @return list<array<string,mixed>>
     */
    public function allBlocks(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'SELECT id, entity_type, entity_id, block_type, content, position, active, created_at, updated_at
             FROM content_blocks
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Update the content payload of a block.
     *
     * @param array<string,mixed> $content New JSON-serialisable content payload.
     * @return bool True if found and updated.
     * @throws \InvalidArgumentException on empty content.
     */
    public function update(int $id, array $content): bool
    {
        if (empty($content)) {
            throw new \InvalidArgumentException('content must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE content_blocks SET content = :content, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            ':content' => json_encode($content, JSON_UNESCAPED_UNICODE),
            ':now'     => $now,
            ':id'      => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Change the position of a block.
     *
     * @return bool True if found and updated.
     */
    public function reorder(int $id, int $position): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE content_blocks SET position = :pos, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([':pos' => $position, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Deactivate a block (hide from default list; retains data).
     *
     * @return bool True if found and deactivated.
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->db()->prepare('UPDATE content_blocks SET active = 0 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reactivate a block.
     *
     * @return bool True if found and activated.
     */
    public function activate(int $id): bool
    {
        $stmt = $this->db()->prepare('UPDATE content_blocks SET active = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hard-delete a block.
     *
     * @return bool True if found and deleted.
     */
    public function remove(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM content_blocks WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hard-delete all blocks for an entity.
     *
     * @return int Number of rows deleted.
     */
    public function clear(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt                    = $this->db()->prepare(
            'DELETE FROM content_blocks WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount();
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
