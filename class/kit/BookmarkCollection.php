<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * BookmarkCollection — user-curated collections of bookmarked entities.
 *
 * Users save any entity (posts, products, videos…) into named collections.
 * The default collection is 'default'. Collections are per-user.
 *
 * ## Usage
 *
 * ```php
 * $bc = new BookmarkCollection($pdo);
 *
 * // Save a bookmark
 * $bc->add('user-1', 'post', '42');
 * $bc->add('user-1', 'post', '7', 'Reading list');
 *
 * // List bookmarks
 * $bc->list('user-1');               // all
 * $bc->list('user-1', 'Reading list'); // in collection
 *
 * // Check
 * $bc->isBookmarked('user-1', 'post', '42');
 *
 * // Remove
 * $bc->remove('user-1', 'post', '42');
 *
 * // Move to another collection
 * $bc->move('user-1', 'post', '42', 'Favourites');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE bookmarks (
 *     id              INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id         VARCHAR(255) NOT NULL,
 *     collection_name VARCHAR(255) NOT NULL DEFAULT 'default',
 *     entity_type     VARCHAR(100) NOT NULL,
 *     entity_id       VARCHAR(255) NOT NULL,
 *     created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, entity_type, entity_id)
 * );
 * ```
 */
final class BookmarkCollection
{
    private const DEFAULT_COLLECTION = 'default';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an entity to a user's collection.
     *
     * Idempotent — bookmarking the same entity again updates the collection name.
     *
     * @throws \InvalidArgumentException if user_id, entity_type, or entity_id is empty.
     */
    public function add(
        string $userId,
        string $entityType,
        string $entityId,
        string $collectionName = self::DEFAULT_COLLECTION
    ): void {
        [$userId, $entityType, $entityId] = $this->normalise($userId, $entityType, $entityId);
        $collectionName = $collectionName === '' ? self::DEFAULT_COLLECTION : trim($collectionName);

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO bookmarks (user_id, collection_name, entity_type, entity_id)
                 VALUES (:uid, :col, :type, :eid)
                 ON CONFLICT (user_id, entity_type, entity_id)
                 DO UPDATE SET collection_name = excluded.collection_name'
            )->execute([':uid' => $userId, ':col' => $collectionName, ':type' => $entityType, ':eid' => $entityId]);
        } else {
            $db->prepare(
                'INSERT INTO bookmarks (user_id, collection_name, entity_type, entity_id)
                 VALUES (:uid, :col, :type, :eid)
                 ON DUPLICATE KEY UPDATE collection_name = VALUES(collection_name)'
            )->execute([':uid' => $userId, ':col' => $collectionName, ':type' => $entityType, ':eid' => $entityId]);
        }
    }

    /**
     * Remove a bookmark.
     *
     * @return bool True if the bookmark existed and was removed.
     */
    public function remove(string $userId, string $entityType, string $entityId): bool
    {
        [$userId, $entityType, $entityId] = $this->normalise($userId, $entityType, $entityId);
        $stmt = $this->db()->prepare(
            'DELETE FROM bookmarks WHERE user_id = :uid AND entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':uid' => $userId, ':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Move a bookmark to a different collection.
     *
     * @return bool True if the bookmark existed and was moved.
     */
    public function move(
        string $userId,
        string $entityType,
        string $entityId,
        string $collectionName
    ): bool {
        [$userId, $entityType, $entityId] = $this->normalise($userId, $entityType, $entityId);
        $collectionName = trim($collectionName) ?: self::DEFAULT_COLLECTION;
        $stmt = $this->db()->prepare(
            'UPDATE bookmarks SET collection_name = :col
             WHERE user_id = :uid AND entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':col' => $collectionName, ':uid' => $userId, ':type' => $entityType, ':eid' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if an entity is bookmarked by a user (any collection).
     */
    public function isBookmarked(string $userId, string $entityType, string $entityId): bool
    {
        [$userId, $entityType, $entityId] = $this->normalise($userId, $entityType, $entityId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM bookmarks WHERE user_id = :uid AND entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':uid' => $userId, ':type' => $entityType, ':eid' => $entityId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * List bookmarks for a user, optionally filtered by collection.
     *
     * @return list<array<string,mixed>>
     */
    public function list(string $userId, ?string $collectionName = null): array
    {
        $userId = trim($userId);
        if ($collectionName !== null) {
            $stmt = $this->db()->prepare(
                'SELECT id, user_id, collection_name, entity_type, entity_id, created_at
                 FROM bookmarks
                 WHERE user_id = :uid AND collection_name = :col
                 ORDER BY id DESC'
            );
            $stmt->execute([':uid' => $userId, ':col' => $collectionName]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, user_id, collection_name, entity_type, entity_id, created_at
                 FROM bookmarks WHERE user_id = :uid ORDER BY id DESC'
            );
            $stmt->execute([':uid' => $userId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all collection names for a user with bookmark counts.
     *
     * @return list<array{collection_name: string, count: int}>
     */
    public function collections(string $userId): array
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare(
            'SELECT collection_name, COUNT(*) AS cnt FROM bookmarks
             WHERE user_id = :uid GROUP BY collection_name ORDER BY collection_name ASC'
        );
        $stmt->execute([':uid' => $userId]);
        return array_map(
            static fn (array $r) => ['collection_name' => (string)$r['collection_name'], 'count' => (int)$r['cnt']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Count bookmarks for a user (optionally within a collection).
     */
    public function count(string $userId, ?string $collectionName = null): int
    {
        $userId = trim($userId);
        if ($collectionName !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM bookmarks WHERE user_id = :uid AND collection_name = :col'
            );
            $stmt->execute([':uid' => $userId, ':col' => $collectionName]);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM bookmarks WHERE user_id = :uid');
            $stmt->execute([':uid' => $userId]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Remove all bookmarks in a collection for a user.
     *
     * @return int Number of rows deleted.
     */
    public function clearCollection(string $userId, string $collectionName): int
    {
        $userId         = trim($userId);
        $collectionName = trim($collectionName);
        $stmt = $this->db()->prepare(
            'DELETE FROM bookmarks WHERE user_id = :uid AND collection_name = :col'
        );
        $stmt->execute([':uid' => $userId, ':col' => $collectionName]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string, string}
     */
    private function normalise(string $userId, string $entityType, string $entityId): array
    {
        $userId     = trim($userId);
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        return [$userId, $entityType, $entityId];
    }
}
