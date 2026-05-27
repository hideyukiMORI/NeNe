<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ContentTag — flexible tagging of arbitrary entities.
 *
 * Tags are normalised lowercase strings (letters, digits, hyphens). Each
 * entity-tag pair is unique. You can attach multiple tags to an entity,
 * search for entities by tag, and retrieve the tag cloud with usage counts.
 *
 * This helper intentionally avoids a separate tags master table — tag strings
 * are stored inline. Use this when tags are ad-hoc and a canonical tag
 * registry is not needed.
 *
 * ## Usage
 *
 * ```php
 * $ct = new ContentTag($pdo);
 *
 * // Tag an entity
 * $ct->tag('article', '10', ['php', 'testing', 'oop']);
 *
 * // Query
 * $tags     = $ct->tagsFor('article', '10');
 * $entities = $ct->entitiesWith('article', 'testing');
 * $cloud    = $ct->cloud('article');
 *
 * // Remove
 * $ct->untag('article', '10', 'oop');
 * $ct->clearAll('article', '10');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE content_tags (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     tag         VARCHAR(100) NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id, tag)
 * );
 * ```
 */
final class ContentTag
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a single tag to an entity (idempotent — ignores duplicates).
     *
     * @return bool True if a new row was inserted; false if already tagged.
     * @throws \InvalidArgumentException on empty entityType/entityId/tag.
     */
    public function tagOne(string $entityType, string $entityId, string $tag): bool
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $tag = $this->normalise($tag);

        try {
            $stmt = $this->db()->prepare(
                'INSERT INTO content_tags (entity_type, entity_id, tag)
                 VALUES (:type, :eid, :tag)'
            );
            $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':tag' => $tag]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException) {
            // UNIQUE violation — already tagged
            return false;
        }
    }

    /**
     * Add multiple tags to an entity at once (idempotent).
     *
     * @param list<string> $tags
     * @return int Number of new tags added.
     */
    public function tag(string $entityType, string $entityId, array $tags): int
    {
        $added = 0;
        foreach ($tags as $t) {
            if ($this->tagOne($entityType, $entityId, $t)) {
                $added++;
            }
        }
        return $added;
    }

    /**
     * Remove a single tag from an entity.
     *
     * @return bool True if found and removed.
     */
    public function untag(string $entityType, string $entityId, string $tag): bool
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $tag = $this->normalise($tag);
        $stmt = $this->db()->prepare(
            'DELETE FROM content_tags
             WHERE entity_type = :type AND entity_id = :eid AND tag = :tag'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':tag' => $tag]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove all tags from an entity.
     *
     * @return int Number of rows deleted.
     */
    public function clearAll(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM content_tags WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount();
    }

    /**
     * Check whether an entity has a specific tag.
     */
    public function hasTag(string $entityType, string $entityId, string $tag): bool
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $tag  = $this->normalise($tag);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM content_tags
             WHERE entity_type = :type AND entity_id = :eid AND tag = :tag'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':tag' => $tag]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * List all tags for an entity (alphabetical).
     *
     * @return list<string>
     */
    public function tagsFor(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->validateEntity($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT tag FROM content_tags
             WHERE entity_type = :type AND entity_id = :eid
             ORDER BY tag ASC'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * List entity IDs of a given type that have a specific tag.
     *
     * @return list<string>
     */
    public function entitiesWith(string $entityType, string $tag): array
    {
        $tag  = $this->normalise($tag);
        $stmt = $this->db()->prepare(
            'SELECT entity_id FROM content_tags
             WHERE entity_type = :type AND tag = :tag
             ORDER BY entity_id ASC'
        );
        $stmt->execute([':type' => trim($entityType), ':tag' => $tag]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Return the tag cloud for an entity type — tag => count, sorted by count desc.
     *
     * @return array<string,int>
     */
    public function cloud(string $entityType): array
    {
        $stmt = $this->db()->prepare(
            'SELECT tag, COUNT(*) AS cnt FROM content_tags
             WHERE entity_type = :type
             GROUP BY tag
             ORDER BY cnt DESC, tag ASC'
        );
        $stmt->execute([':type' => trim($entityType)]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['tag']] = (int)$row['cnt'];
        }
        return $result;
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

    /**
     * Normalise a tag: trim, lowercase, collapse non-alnum/hyphen to hyphens.
     *
     * @throws \InvalidArgumentException on empty tag after normalisation.
     */
    private function normalise(string $tag): string
    {
        $tag = strtolower(trim($tag));
        $tag = (string)preg_replace('/[^a-z0-9\-]+/', '-', $tag);
        $tag = trim($tag, '-');
        if ($tag === '') {
            throw new \InvalidArgumentException('tag must not be empty after normalisation.');
        }
        return $tag;
    }
}
