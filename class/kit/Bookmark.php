<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Generic per-user bookmark / saved-item manager.
 *
 * Users can bookmark any entity identified by (entity_type, entity_id).
 * The same helper serves wishlists, favourites, "read later" lists, etc.
 * An optional `collection` field allows grouping within a user's bookmarks.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE bookmarks (
 *     id          INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     entity_type VARCHAR(64)  NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     collection  VARCHAR(64)  NOT NULL DEFAULT '',
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, entity_type, entity_id)
 * );
 * CREATE INDEX idx_bookmarks_user ON bookmarks (user_id, collection);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $bm = new Bookmark($pdo);
 *
 * $bm->save('user:1', 'article', '42');
 * $bm->save('user:1', 'article', '99', 'read-later');
 *
 * $bm->isSaved('user:1', 'article', '42');  // true
 * $bm->list('user:1', 'article');            // all article bookmarks
 * $bm->list('user:1', 'article', 'read-later'); // filtered by collection
 *
 * $bm->remove('user:1', 'article', '42');   // true
 * ```
 */
final class Bookmark
{
    private const TABLE = 'bookmarks';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Save a bookmark.
     *
     * Idempotent: saving an already-bookmarked item is a no-op.
     *
     * @param string $userId     Application-level user identifier.
     * @param string $entityType Entity type (e.g. 'article', 'product').
     * @param string $entityId   Entity ID.
     * @param string $collection Optional collection name (e.g. 'read-later', 'wishlist').
     * @return bool True if newly bookmarked, false if already existed.
     */
    public function save(
        string $userId,
        string $entityType,
        string $entityId,
        string $collection = '',
    ): bool {
        $db     = $this->db ?? PdoConnection::getInstance();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ignore = $driver === 'sqlite' ? 'OR IGNORE' : 'IGNORE';

        $stmt = $db->prepare(
            'INSERT ' . $ignore . ' INTO ' . $this->table .
            ' (user_id, entity_type, entity_id, collection)' .
            ' VALUES (:u, :etype, :eid, :col)'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':eid', $entityId, PDO::PARAM_STR);
        $stmt->bindValue(':col', $collection, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a bookmark.
     *
     * Returns true if removed, false if not bookmarked.
     *
     * @param string $userId     Application-level user identifier.
     * @param string $entityType Entity type.
     * @param string $entityId   Entity ID.
     */
    public function remove(string $userId, string $entityType, string $entityId): bool
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'DELETE FROM ' . $this->table .
            ' WHERE user_id = :u AND entity_type = :etype AND entity_id = :eid'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':eid', $entityId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether an item is bookmarked by a user.
     *
     * @param string $userId     Application-level user identifier.
     * @param string $entityType Entity type.
     * @param string $entityId   Entity ID.
     */
    public function isSaved(string $userId, string $entityType, string $entityId): bool
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT 1 FROM ' . $this->table .
            ' WHERE user_id = :u AND entity_type = :etype AND entity_id = :eid LIMIT 1'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
        $stmt->bindValue(':eid', $entityId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * List bookmarks for a user, optionally filtered by entity type and collection.
     *
     * Ordered by created_at DESC (most recently bookmarked first).
     *
     * @param  string      $userId     Application-level user identifier.
     * @param  string|null $entityType Limit to this entity type. Null = all types.
     * @param  string|null $collection Limit to this collection. Null = all collections.
     * @return list<array{id:int,entity_type:string,entity_id:string,collection:string,created_at:string}>
     */
    public function list(
        string $userId,
        ?string $entityType = null,
        ?string $collection = null,
    ): array {
        $db  = $this->db ?? PdoConnection::getInstance();
        $sql = 'SELECT id, entity_type, entity_id, collection, created_at' .
               ' FROM ' . $this->table .
               ' WHERE user_id = :u';

        if ($entityType !== null) {
            $sql .= ' AND entity_type = :etype';
        }
        if ($collection !== null) {
            $sql .= ' AND collection = :col';
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        if ($entityType !== null) {
            $stmt->bindValue(':etype', $entityType, PDO::PARAM_STR);
        }
        if ($collection !== null) {
            $stmt->bindValue(':col', $collection, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'id'          => (int)$r['id'],
            'entity_type' => (string)$r['entity_type'],
            'entity_id'   => (string)$r['entity_id'],
            'collection'  => (string)$r['collection'],
            'created_at'  => (string)$r['created_at'],
        ], $rows);
    }
}
