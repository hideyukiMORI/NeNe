<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Wishlist — per-user saved items using entity-agnostic (type, id) pairs.
 *
 * Items are deduped per user: saving the same entity twice is a no-op.
 * The optional `note` field lets users attach a short personal memo.
 *
 * ## Usage
 *
 * ```php
 * $wl = new Wishlist($pdo);
 *
 * // Save
 * $wl->add('user-1', 'product', '42');
 * $wl->add('user-1', 'product', '99', 'Birthday gift idea');
 *
 * // Check
 * $wl->contains('user-1', 'product', '42'); // true
 *
 * // List
 * $items = $wl->list('user-1');
 * $items = $wl->list('user-1', entityType: 'product');
 *
 * // Remove
 * $wl->remove('user-1', 'product', '42');
 *
 * // Count
 * $wl->count('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE wishlists (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     entity_type VARCHAR(100) NOT NULL,
 *     entity_id   VARCHAR(255) NOT NULL,
 *     note        TEXT         NOT NULL DEFAULT '',
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, entity_type, entity_id)
 * );
 * ```
 */
final class Wishlist
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an entity to the user's wishlist.
     *
     * Idempotent — adding the same entity twice is a no-op (INSERT OR IGNORE).
     * To update the note on an existing item use `updateNote()`.
     *
     * @throws \InvalidArgumentException if entity_type or entity_id is empty.
     */
    public function add(string $userId, string $entityType, string $entityId, string $note = ''): void
    {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);

        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO wishlists (user_id, entity_type, entity_id, note) VALUES (:u, :t, :e, :n)'
            : 'INSERT IGNORE INTO wishlists (user_id, entity_type, entity_id, note) VALUES (:u, :t, :e, :n)';

        $db->prepare($sql)->execute([':u' => $userId, ':t' => $entityType, ':e' => $entityId, ':n' => $note]);
    }

    /**
     * Remove an entity from the user's wishlist.
     *
     * @return bool True if the item existed and was removed.
     */
    public function remove(string $userId, string $entityType, string $entityId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM wishlists WHERE user_id = :u AND entity_type = :t AND entity_id = :e'
        );
        $stmt->execute([':u' => $userId, ':t' => $entityType, ':e' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if an entity is in the user's wishlist.
     */
    public function contains(string $userId, string $entityType, string $entityId): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM wishlists WHERE user_id = :u AND entity_type = :t AND entity_id = :e LIMIT 1'
        );
        $stmt->execute([':u' => $userId, ':t' => $entityType, ':e' => $entityId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * List items in the user's wishlist.
     *
     * @param  string|null $entityType Optional filter by entity type.
     * @return list<array<string, mixed>>
     */
    public function list(string $userId, ?string $entityType = null): array
    {
        if ($entityType !== null) {
            $stmt = $this->db()->prepare(
                'SELECT * FROM wishlists WHERE user_id = :u AND entity_type = :t ORDER BY created_at DESC'
            );
            $stmt->execute([':u' => $userId, ':t' => $entityType]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT * FROM wishlists WHERE user_id = :u ORDER BY created_at DESC'
            );
            $stmt->execute([':u' => $userId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the note on a wishlist item.
     *
     * @return bool True if the item existed and the note was updated.
     */
    public function updateNote(string $userId, string $entityType, string $entityId, string $note): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE wishlists SET note = :n WHERE user_id = :u AND entity_type = :t AND entity_id = :e'
        );
        $stmt->execute([':n' => $note, ':u' => $userId, ':t' => $entityType, ':e' => $entityId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Count items in the user's wishlist (all types).
     */
    public function count(string $userId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM wishlists WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Clear all wishlist items for a user.
     *
     * @return int Number of items removed.
     */
    public function clear(string $userId): int
    {
        $stmt = $this->db()->prepare('DELETE FROM wishlists WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
