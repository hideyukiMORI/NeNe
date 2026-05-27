<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ReactionCounter — emoji/type reactions on any entity with per-user state.
 *
 * Users can react to any entity (post, comment, message) with a named reaction
 * type (e.g. "like", "heart", "laugh"). Each user may have at most one active
 * reaction per entity; reacting again with the same type toggles it off (remove),
 * while reacting with a different type switches the reaction.
 *
 * ## Usage
 *
 * ```php
 * $rc = new ReactionCounter($pdo);
 *
 * // Add or toggle a reaction
 * $rc->react('post', '42', 'user-1', 'like');
 *
 * // Get total counts per type
 * $counts = $rc->counts('post', '42');
 * // ['like' => 5, 'heart' => 2]
 *
 * // Get user's current reaction
 * $rc->userReaction('post', '42', 'user-1'); // 'like' or null
 *
 * // Remove reaction
 * $rc->unreact('post', '42', 'user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE reactions (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     entity_type  VARCHAR(100) NOT NULL,
 *     entity_id    VARCHAR(255) NOT NULL,
 *     user_id      VARCHAR(255) NOT NULL,
 *     reaction     VARCHAR(50)  NOT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (entity_type, entity_id, user_id)
 * );
 * ```
 */
final class ReactionCounter
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add or switch a reaction for a user on an entity.
     *
     * - If the user has no reaction: adds the given reaction.
     * - If the user has the same reaction: removes it (toggle off).
     * - If the user has a different reaction: switches to the new one.
     *
     * @return 'added'|'removed'|'switched'
     * @throws \InvalidArgumentException if any argument is empty.
     */
    public function react(string $entityType, string $entityId, string $userId, string $reaction): string
    {
        [$entityType, $entityId, $userId, $reaction] = $this->normaliseAll(
            $entityType,
            $entityId,
            $userId,
            $reaction
        );

        $existing = $this->userReaction($entityType, $entityId, $userId);

        if ($existing === null) {
            $this->insertReaction($entityType, $entityId, $userId, $reaction);
            return 'added';
        }

        if ($existing === $reaction) {
            $this->deleteReaction($entityType, $entityId, $userId);
            return 'removed';
        }

        // Switch to new reaction
        $this->db()->prepare(
            'UPDATE reactions SET reaction = :reaction, created_at = CURRENT_TIMESTAMP
             WHERE entity_type = :type AND entity_id = :eid AND user_id = :uid'
        )->execute([':reaction' => $reaction, ':type' => $entityType, ':eid' => $entityId, ':uid' => $userId]);
        return 'switched';
    }

    /**
     * Remove a user's reaction regardless of type.
     *
     * @return bool True if a reaction was removed.
     */
    public function unreact(string $entityType, string $entityId, string $userId): bool
    {
        [$entityType, $entityId, $userId] = $this->normalise3($entityType, $entityId, $userId);
        return $this->deleteReaction($entityType, $entityId, $userId);
    }

    /**
     * Get the current reaction type for a user on an entity, or null if none.
     */
    public function userReaction(string $entityType, string $entityId, string $userId): ?string
    {
        [$entityType, $entityId, $userId] = $this->normalise3($entityType, $entityId, $userId);
        $stmt = $this->db()->prepare(
            'SELECT reaction FROM reactions
             WHERE entity_type = :type AND entity_id = :eid AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Get the total count for a specific reaction type on an entity.
     */
    public function count(string $entityType, string $entityId, string $reaction): int
    {
        $reaction = trim($reaction);
        $stmt     = $this->db()->prepare(
            'SELECT COUNT(*) FROM reactions
             WHERE entity_type = :type AND entity_id = :eid AND reaction = :reaction'
        );
        $stmt->execute([':type' => trim($entityType), ':eid' => trim($entityId), ':reaction' => $reaction]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get counts for all reaction types on an entity.
     *
     * @return array<string, int>  Map of reaction => count, sorted by count DESC.
     */
    public function counts(string $entityType, string $entityId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT reaction, COUNT(*) AS cnt
             FROM reactions
             WHERE entity_type = :type AND entity_id = :eid
             GROUP BY reaction
             ORDER BY cnt DESC, reaction ASC'
        );
        $stmt->execute([':type' => trim($entityType), ':eid' => trim($entityId)]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['reaction']] = (int)$row['cnt'];
        }
        return $result;
    }

    /**
     * Get all users who reacted with a specific type on an entity.
     *
     * @return list<string>
     */
    public function reactors(string $entityType, string $entityId, string $reaction): array
    {
        $stmt = $this->db()->prepare(
            'SELECT user_id FROM reactions
             WHERE entity_type = :type AND entity_id = :eid AND reaction = :reaction
             ORDER BY created_at ASC'
        );
        $stmt->execute([
            ':type'     => trim($entityType),
            ':eid'      => trim($entityId),
            ':reaction' => trim($reaction),
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Get the total number of reactions (all types) on an entity.
     */
    public function total(string $entityType, string $entityId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM reactions WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => trim($entityType), ':eid' => trim($entityId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Remove all reactions for an entity (e.g., on content deletion).
     *
     * @return int Number of reactions removed.
     */
    public function purge(string $entityType, string $entityId): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM reactions WHERE entity_type = :type AND entity_id = :eid'
        );
        $stmt->execute([':type' => trim($entityType), ':eid' => trim($entityId)]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function insertReaction(string $entityType, string $entityId, string $userId, string $reaction): void
    {
        $this->db()->prepare(
            'INSERT INTO reactions (entity_type, entity_id, user_id, reaction)
             VALUES (:type, :eid, :uid, :reaction)'
        )->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId, ':reaction' => $reaction]);
    }

    private function deleteReaction(string $entityType, string $entityId, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM reactions WHERE entity_type = :type AND entity_id = :eid AND user_id = :uid'
        );
        $stmt->execute([':type' => $entityType, ':eid' => $entityId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array{string, string, string}
     */
    private function normalise3(string $entityType, string $entityId, string $userId): array
    {
        $entityType = trim($entityType);
        $entityId   = trim($entityId);
        $userId     = trim($userId);
        if ($entityType === '') {
            throw new \InvalidArgumentException('entity_type must not be empty.');
        }
        if ($entityId === '') {
            throw new \InvalidArgumentException('entity_id must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$entityType, $entityId, $userId];
    }

    /**
     * @return array{string, string, string, string}
     */
    private function normaliseAll(
        string $entityType,
        string $entityId,
        string $userId,
        string $reaction
    ): array {
        [$entityType, $entityId, $userId] = $this->normalise3($entityType, $entityId, $userId);
        $reaction = trim($reaction);
        if ($reaction === '') {
            throw new \InvalidArgumentException('reaction must not be empty.');
        }
        return [$entityType, $entityId, $userId, $reaction];
    }
}
