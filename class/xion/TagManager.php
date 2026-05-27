<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Generic tag/label system for M:N entity-tag relationships.
 *
 * Tags are stored in a `tags` table, and the M:N relationship between
 * entities and tags is stored in a `tag_attachments` table.
 * Entity identity is expressed as (entity_type, entity_id) pairs, making
 * this helper reusable across any entity type in the same application.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE tags (
 *     id         INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     name       VARCHAR(100) NOT NULL UNIQUE,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE tag_attachments (
 *     tag_id      INTEGER      NOT NULL,
 *     entity_type VARCHAR(64)  NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (tag_id, entity_type, entity_id)
 * );
 * CREATE INDEX idx_tag_attachments_entity ON tag_attachments (entity_type, entity_id);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $tags = new TagManager($pdo);
 *
 * // Attach tags (creates them if they don't exist)
 * $tags->attach('post', '42', ['php', 'oop', 'design-patterns']);
 *
 * // Get tags for an entity
 * $tags->tagsFor('post', '42');
 * // ['design-patterns', 'oop', 'php']
 *
 * // Detach a single tag
 * $tags->detach('post', '42', 'oop');
 *
 * // Sync (replace all tags atomically)
 * $tags->syncTags('post', '42', ['php', 'solid']);
 *
 * // Find entities with a tag
 * $tags->entitiesWithTag('php', 'post');
 * // ['42', '99', ...]
 * ```
 */
final class TagManager
{
    private const TABLE_TAGS        = 'tags';
    private const TABLE_ATTACHMENTS = 'tag_attachments';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $tableTags        = self::TABLE_TAGS,
        private readonly string $tableAttachments = self::TABLE_ATTACHMENTS,
    ) {
    }

    /**
     * Attach one or more tags to an entity.
     *
     * Tags are created automatically if they don't exist. Already-attached tags
     * are silently skipped (idempotent).
     *
     * @param string        $entityType Entity type identifier (e.g. 'post', 'product').
     * @param string        $entityId   Entity ID.
     * @param list<string>  $tagNames   Tag names to attach.
     */
    public function attach(string $entityType, string $entityId, array $tagNames): void
    {
        if ($tagNames === []) {
            return;
        }

        $db     = $this->db ?? PdoConnection::getInstance();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ignore = $driver === 'sqlite' ? 'OR IGNORE' : 'IGNORE';

        foreach ($tagNames as $name) {
            $tagId = $this->findOrCreateTag($name, $db, $ignore);

            $stmt = $db->prepare(
                'INSERT ' . $ignore . ' INTO ' . $this->tableAttachments .
                ' (tag_id, entity_type, entity_id)' .
                ' VALUES (:tid, :etype, :eid)'
            );
            $stmt->bindValue(':tid', $tagId, PDO::PARAM_INT);
            $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
            $stmt->bindValue(':eid', $entityId, PDO::PARAM_STR);
            $stmt->execute();
        }
    }

    /**
     * Detach a single tag from an entity.
     *
     * Returns true if detached, false if the tag was not attached.
     *
     * @param string $entityType Entity type identifier.
     * @param string $entityId   Entity ID.
     * @param string $tagName    Tag name to remove.
     */
    public function detach(string $entityType, string $entityId, string $tagName): bool
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'DELETE FROM ' . $this->tableAttachments .
            ' WHERE entity_type = :etype AND entity_id = :eid' .
            ' AND tag_id = (SELECT id FROM ' . $this->tableTags . ' WHERE name = :name LIMIT 1)'
        );
        $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':eid', $entityId, PDO::PARAM_STR);
        $stmt->bindValue(':name', $tagName, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Sync tags for an entity: replaces all existing tags with the given set.
     *
     * Tags not in $tagNames are removed; new tags are created and attached.
     * Passing an empty array removes all tags.
     *
     * @param string        $entityType Entity type identifier.
     * @param string        $entityId   Entity ID.
     * @param list<string>  $tagNames   New complete set of tag names.
     */
    public function syncTags(string $entityType, string $entityId, array $tagNames): void
    {
        $db = $this->db ?? PdoConnection::getInstance();
        $db->beginTransaction();

        try {
            // Remove all current attachments
            $del = $db->prepare(
                'DELETE FROM ' . $this->tableAttachments .
                ' WHERE entity_type = :etype AND entity_id = :eid'
            );
            $del->bindValue(':etype', $entityType, PDO::PARAM_STR);
            $del->bindValue(':eid', $entityId, PDO::PARAM_STR);
            $del->execute();

            // Re-attach the new set
            if ($tagNames !== []) {
                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                $ignore = $driver === 'sqlite' ? 'OR IGNORE' : 'IGNORE';

                foreach ($tagNames as $name) {
                    $tagId = $this->findOrCreateTag($name, $db, $ignore);

                    $ins = $db->prepare(
                        'INSERT ' . $ignore . ' INTO ' . $this->tableAttachments .
                        ' (tag_id, entity_type, entity_id) VALUES (:tid, :etype, :eid)'
                    );
                    $ins->bindValue(':tid', $tagId, PDO::PARAM_INT);
                    $ins->bindValue(':etype', $entityType, PDO::PARAM_STR);
                    $ins->bindValue(':eid', $entityId, PDO::PARAM_STR);
                    $ins->execute();
                }
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Get all tag names attached to an entity, sorted alphabetically.
     *
     * @param  string $entityType Entity type identifier.
     * @param  string $entityId   Entity ID.
     * @return list<string>
     */
    public function tagsFor(string $entityType, string $entityId): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT t.name FROM ' . $this->tableTags . ' t' .
            ' INNER JOIN ' . $this->tableAttachments . ' a ON a.tag_id = t.id' .
            ' WHERE a.entity_type = :etype AND a.entity_id = :eid' .
            ' ORDER BY t.name ASC'
        );
        $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':eid', $entityId, PDO::PARAM_STR);
        $stmt->execute();

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    /**
     * Get entity IDs that have a specific tag, optionally filtered by entity type.
     *
     * @param  string      $tagName    Tag name to search.
     * @param  string|null $entityType Limit to this entity type. Null = all types.
     * @return list<string>
     */
    public function entitiesWithTag(string $tagName, ?string $entityType = null): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $sql  = 'SELECT a.entity_id FROM ' . $this->tableAttachments . ' a' .
                ' INNER JOIN ' . $this->tableTags . ' t ON t.id = a.tag_id' .
                ' WHERE t.name = :name';

        if ($entityType !== null) {
            $sql .= ' AND a.entity_type = :etype';
        }

        $sql .= ' ORDER BY a.entity_id ASC';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':name', $tagName, PDO::PARAM_STR);
        if ($entityType !== null) {
            $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
        }
        $stmt->execute();

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'entity_id');
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    /**
     * Find a tag by name or create it. Returns the tag ID.
     */
    private function findOrCreateTag(string $name, PDO $db, string $ignore): int
    {
        $ins = $db->prepare(
            'INSERT ' . $ignore . ' INTO ' . $this->tableTags . ' (name) VALUES (:name)'
        );
        $ins->bindValue(':name', $name, PDO::PARAM_STR);
        $ins->execute();

        $sel = $db->prepare('SELECT id FROM ' . $this->tableTags . ' WHERE name = :name LIMIT 1');
        $sel->bindValue(':name', $name, PDO::PARAM_STR);
        $sel->execute();

        return (int)$sel->fetchColumn();
    }
}
