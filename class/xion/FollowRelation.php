<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * User follow/unfollow relationship management.
 *
 * Manages directed follower–followee relationships between users.
 * Self-follow is prevented at the application layer.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE follow_relations (
 *     follower_id VARCHAR(255) NOT NULL,
 *     followee_id VARCHAR(255) NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (follower_id, followee_id)
 * );
 * CREATE INDEX idx_follow_relations_followee ON follow_relations (followee_id);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $fr = new FollowRelation($pdo);
 *
 * $fr->follow('user:1', 'user:2');          // user:1 follows user:2
 * $fr->isFollowing('user:1', 'user:2');     // true
 * $fr->followers('user:2');                 // [['follower_id' => 'user:1', 'created_at' => '...']]
 * $fr->following('user:1');                 // [['followee_id' => 'user:2', 'created_at' => '...']]
 * $fr->isMutual('user:1', 'user:2');        // false (user:2 hasn't followed back)
 * $fr->unfollow('user:1', 'user:2');        // true
 * ```
 */
final class FollowRelation
{
    private const TABLE = 'follow_relations';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Follow a user.
     *
     * Returns true if the follow was created, false if already following or self-follow.
     *
     * @param string $followerId The user who is following.
     * @param string $followeeId The user being followed.
     */
    public function follow(string $followerId, string $followeeId): bool
    {
        if ($followerId === $followeeId) {
            return false;
        }

        $db     = $this->db ?? PdoConnection::getInstance();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ignore = $driver === 'sqlite' ? 'OR IGNORE' : 'IGNORE';

        $stmt = $db->prepare(
            'INSERT ' . $ignore . ' INTO ' . $this->table .
            ' (follower_id, followee_id) VALUES (:follower, :followee)'
        );
        $stmt->bindValue(':follower', $followerId, PDO::PARAM_STR);
        $stmt->bindValue(':followee', $followeeId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Unfollow a user.
     *
     * Returns true if the follow was removed, false if not following.
     *
     * @param string $followerId The user who is unfollowing.
     * @param string $followeeId The user being unfollowed.
     */
    public function unfollow(string $followerId, string $followeeId): bool
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'DELETE FROM ' . $this->table .
            ' WHERE follower_id = :follower AND followee_id = :followee'
        );
        $stmt->bindValue(':follower', $followerId, PDO::PARAM_STR);
        $stmt->bindValue(':followee', $followeeId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether $followerId follows $followeeId.
     */
    public function isFollowing(string $followerId, string $followeeId): bool
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT 1 FROM ' . $this->table .
            ' WHERE follower_id = :follower AND followee_id = :followee LIMIT 1'
        );
        $stmt->bindValue(':follower', $followerId, PDO::PARAM_STR);
        $stmt->bindValue(':followee', $followeeId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * List users who follow $userId, ordered by created_at ASC.
     *
     * @param  string $userId The user whose followers are returned.
     * @return list<array{follower_id: string, created_at: string}>
     */
    public function followers(string $userId): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT follower_id, created_at FROM ' . $this->table .
            ' WHERE followee_id = :u ORDER BY created_at ASC, follower_id ASC'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'follower_id' => (string)$r['follower_id'],
            'created_at'  => (string)$r['created_at'],
        ], $rows);
    }

    /**
     * List users that $userId is following, ordered by created_at ASC.
     *
     * @param  string $userId The user whose followed list is returned.
     * @return list<array{followee_id: string, created_at: string}>
     */
    public function following(string $userId): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT followee_id, created_at FROM ' . $this->table .
            ' WHERE follower_id = :u ORDER BY created_at ASC, followee_id ASC'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'followee_id' => (string)$r['followee_id'],
            'created_at'  => (string)$r['created_at'],
        ], $rows);
    }

    /**
     * Check whether $userA and $userB mutually follow each other.
     */
    public function isMutual(string $userA, string $userB): bool
    {
        return $this->isFollowing($userA, $userB) && $this->isFollowing($userB, $userA);
    }
}
