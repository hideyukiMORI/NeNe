<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * TagIndex — attach tags to any entity and query by tag.
 *
 * Entities are identified by (entity_type, entity_id). Tags are normalised
 * to lowercase-trimmed strings. Supports adding, removing, listing, and
 * cross-entity tag queries.
 *
 * ## Usage
 *
 * ```php
 * $ti = new TagIndex($pdo);
 *
 * // Tag a post
 * $ti->add('post', '42', ['php', 'web', 'tutorial']);
 *
 * // List tags for a post
 * $ti->tags('post', '42'); // ['php', 'tutorial', 'web']
 *
 * // Find all posts with a given tag
 * $ti->byTag('post', 'php'); // ['42', '7', ...]
 *
 * // Find posts matching ALL given tags
 * $ti->byAllTags('post', ['php', 'web']);
 *
 * // Remove a tag
 * $ti->remove('post', '42', 'web');
 *
 * // Remove all tags from an entity
 * $ti->clear('post', '42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE tag_index (
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     tag          VARCHAR(100) NOT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (entity_type, entity_id, tag)
 * );
 * ```
 */
final class TagIndex
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add one or more tags to an entity (idempotent).
     *
     * Tags are normalised to lowercase-trimmed strings. Empty strings are ignored.
     *
     * @param  list<string> $tags
     * @throws \InvalidArgumentException if entity_type or entity_id is empty, or tags list is empty.
     */
    public function add(string $entityType, string $entityId, array $tags): void
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $tags = $this->normaliseTags($tags);
        if (empty($tags)) {
            throw new \InvalidArgumentException('tags list must not be empty after normalisation.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO tag_index (entity_type, entity_id, tag) VALUES (:type, :id, :tag)'
            : 'INSERT IGNORE INTO tag_index (entity_type, entity_id, tag) VALUES (:type, :id, :tag)';

        $stmt = $db->prepare($sql);
        foreach ($tags as $tag) {
            $stmt->execute([':type' => $entityType, ':id' => $entityId, ':tag' => $tag]);
        }
    }

    /**
     * Remove a specific tag from an entity.
     *
     * @return bool True if the tag existed and was removed.
     */
    public function remove(string $entityType, string $entityId, string $tag): bool
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $tag  = strtolower(trim($tag));
        $stmt = $this->db()->prepare(
            'DELETE FROM tag_index WHERE entity_type = :type AND entity_id = :id AND tag = :tag'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId, ':tag' => $tag]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove all tags from an entity.
     *
     * @return int Number of tags removed.
     */
    public function clear(string $entityType, string $entityId): int
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM tag_index WHERE entity_type = :type AND entity_id = :id'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        return $stmt->rowCount();
    }

    /**
     * Replace all tags on an entity with a new set.
     *
     * @param  list<string> $tags
     */
    public function set(string $entityType, string $entityId, array $tags): void
    {
        $this->clear($entityType, $entityId);
        if (!empty($tags)) {
            $this->add($entityType, $entityId, $tags);
        }
    }

    /**
     * Get all tags attached to an entity (sorted alphabetically).
     *
     * @return list<string>
     */
    public function tags(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT tag FROM tag_index WHERE entity_type = :type AND entity_id = :id ORDER BY tag ASC'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'tag');
    }

    /**
     * Check whether an entity has a specific tag.
     */
    public function hasTag(string $entityType, string $entityId, string $tag): bool
    {
        [$entityType, $entityId] = $this->normalise($entityType, $entityId);
        $tag  = strtolower(trim($tag));
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM tag_index
             WHERE entity_type = :type AND entity_id = :id AND tag = :tag'
        );
        $stmt->execute([':type' => $entityType, ':id' => $entityId, ':tag' => $tag]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Find all entity IDs of a given type that have a specific tag.
     *
     * @return list<string>
     */
    public function byTag(string $entityType, string $tag): array
    {
        $entityType = trim($entityType);
        $tag        = strtolower(trim($tag));
        $stmt       = $this->db()->prepare(
            'SELECT entity_id FROM tag_index WHERE entity_type = :type AND tag = :tag ORDER BY entity_id ASC'
        );
        $stmt->execute([':type' => $entityType, ':tag' => $tag]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'entity_id');
    }

    /**
     * Find entity IDs that have ALL of the given tags (AND query).
     *
     * @param  list<string> $tags
     * @return list<string>
     */
    public function byAllTags(string $entityType, array $tags): array
    {
        $entityType = trim($entityType);
        $tags       = $this->normaliseTags($tags);
        if (empty($tags)) {
            return [];
        }

        $tagCount = count($tags);
        $in       = implode(',', array_fill(0, $tagCount, '?'));
        $params   = array_merge([$entityType], $tags);

        // $tagCount is embedded literally — it is derived from count($tags), not user input
        $stmt = $this->db()->prepare(
            "SELECT entity_id FROM tag_index
             WHERE entity_type = ? AND tag IN ({$in})
             GROUP BY entity_id
             HAVING COUNT(DISTINCT tag) = {$tagCount}
             ORDER BY entity_id ASC"
        );
        $stmt->execute($params);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'entity_id');
    }

    /**
     * Count how many entities of a given type have a specific tag.
     */
    public function countByTag(string $entityType, string $tag): int
    {
        $entityType = trim($entityType);
        $tag        = strtolower(trim($tag));
        $stmt       = $this->db()->prepare(
            'SELECT COUNT(*) FROM tag_index WHERE entity_type = :type AND tag = :tag'
        );
        $stmt->execute([':type' => $entityType, ':tag' => $tag]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * List all unique tags used for a given entity type, with usage counts.
     *
     * @return list<array{tag: string, count: int}>
     */
    public function popularTags(string $entityType, int $limit = 20): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT tag, COUNT(*) AS cnt FROM tag_index
             WHERE entity_type = :type
             GROUP BY tag
             ORDER BY cnt DESC, tag ASC
             LIMIT {$limit}"
        );
        $stmt->execute([':type' => $entityType]);
        return array_map(
            static fn (array $r) => ['tag' => (string)$r['tag'], 'count' => (int)$r['cnt']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $entityType, string $entityId): array
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
     * @param  list<string> $tags
     * @return list<string>
     */
    private function normaliseTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $t) {
            $t = strtolower(trim((string)$t));
            if ($t !== '' && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }
        return $out;
    }
}
