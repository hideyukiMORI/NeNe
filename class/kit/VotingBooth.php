<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Upvote / downvote system with toggle semantics and per-user vote state.
 *
 * One vote per user per target. Same-direction vote toggles (removes) the vote.
 * Opposite-direction vote switches direction. UNIQUE constraint enforces the
 * one-vote rule at the DB level.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE votes (
 *     id         INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     target_id  VARCHAR(255) NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL,
 *     direction  VARCHAR(4)   NOT NULL CHECK (direction IN ('up', 'down')),
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (target_id, user_id)
 * );
 * CREATE INDEX idx_votes_target ON votes (target_id);
 * ```
 *
 * ## Toggle semantics
 *
 * | Previous vote | New vote | Result |
 * |---------------|----------|--------|
 * | none          | up/down  | INSERT (vote added) |
 * | up            | up       | DELETE (vote removed — toggle) |
 * | down          | down     | DELETE (vote removed — toggle) |
 * | up            | down     | UPDATE (direction switched) |
 * | down          | up       | UPDATE (direction switched) |
 *
 * ## Usage
 *
 * ```php
 * $booth = new VotingBooth();
 *
 * // Cast / toggle / switch a vote
 * $result = $booth->cast('post:42', 'user:1', 'up');
 * // $result['vote'] is 'up', 'down', or null (removed)
 * // $result['score'] is the current score (upvotes - downvotes)
 *
 * // Validate direction input
 * if (!VotingBooth::validDirection($input)) {
 *     // 422
 * }
 *
 * // Get score
 * $score = $booth->score('post:42'); // ['up' => 3, 'down' => 1, 'score' => 2]
 *
 * // Get user's current vote
 * $vote = $booth->userVote('post:42', 'user:1'); // 'up', 'down', or null
 * ```
 */
final class VotingBooth
{
    private const TABLE      = 'votes';
    private const VALID_DIRS = ['up', 'down'];

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Cast, toggle, or switch a vote.
     *
     * @param  string|int $targetId  Votable resource identifier (e.g. "post:42").
     * @param  string|int $userId    Voter identifier.
     * @param  string     $direction 'up' or 'down'.
     * @return array{vote: string|null, score: int} Current vote state and score.
     * @throws \InvalidArgumentException If direction is not 'up' or 'down'.
     */
    public function cast(string|int $targetId, string|int $userId, string $direction): array
    {
        if (!self::validDirection($direction)) {
            throw new \InvalidArgumentException(
                "Invalid direction '{$direction}'. Allowed: up, down."
            );
        }

        $db       = $this->db ?? PdoConnection::getInstance();
        $tId      = (string)$targetId;
        $uId      = (string)$userId;
        $existing = $this->userVote($targetId, $userId);

        if ($existing === $direction) {
            // Same direction — toggle (remove)
            $stmt = $db->prepare(
                'DELETE FROM ' . $this->table . ' WHERE target_id = :t AND user_id = :u'
            );
            $stmt->bindValue(':t', $tId, PDO::PARAM_STR);
            $stmt->bindValue(':u', $uId, PDO::PARAM_STR);
            $stmt->execute();
            $currentVote = null;
        } elseif ($existing !== null) {
            // Opposite direction — switch
            $stmt = $db->prepare(
                'UPDATE ' . $this->table .
                ' SET direction = :d WHERE target_id = :t AND user_id = :u'
            );
            $stmt->bindValue(':d', $direction, PDO::PARAM_STR);
            $stmt->bindValue(':t', $tId, PDO::PARAM_STR);
            $stmt->bindValue(':u', $uId, PDO::PARAM_STR);
            $stmt->execute();
            $currentVote = $direction;
        } else {
            // No existing vote — insert
            $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $stmt = $db->prepare(
                'INSERT INTO ' . $this->table .
                ' (target_id, user_id, direction, created_at) VALUES (:t, :u, :d, :c)'
            );
            $stmt->bindValue(':t', $tId, PDO::PARAM_STR);
            $stmt->bindValue(':u', $uId, PDO::PARAM_STR);
            $stmt->bindValue(':d', $direction, PDO::PARAM_STR);
            $stmt->bindValue(':c', $now, PDO::PARAM_STR);
            $stmt->execute();
            $currentVote = $direction;
        }

        $score = $this->score($targetId);
        return ['vote' => $currentVote, 'score' => $score['score']];
    }

    /**
     * Get vote score for a target.
     *
     * @param  string|int $targetId Votable resource identifier.
     * @return array{up: int, down: int, score: int}
     */
    public function score(string|int $targetId): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $tId  = (string)$targetId;

        $stmt = $db->prepare(
            'SELECT direction, COUNT(*) AS cnt FROM ' . $this->table .
            ' WHERE target_id = :t GROUP BY direction'
        );
        $stmt->bindValue(':t', $tId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $up   = 0;
        $down = 0;
        foreach ($rows as $row) {
            if ($row['direction'] === 'up') {
                $up = (int)$row['cnt'];
            } elseif ($row['direction'] === 'down') {
                $down = (int)$row['cnt'];
            }
        }

        return ['up' => $up, 'down' => $down, 'score' => $up - $down];
    }

    /**
     * Get the current vote direction for a specific user on a target.
     *
     * @param  string|int $targetId Votable resource identifier.
     * @param  string|int $userId   Voter identifier.
     * @return string|null 'up', 'down', or null (no vote).
     */
    public function userVote(string|int $targetId, string|int $userId): ?string
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT direction FROM ' . $this->table .
            ' WHERE target_id = :t AND user_id = :u LIMIT 1'
        );
        $stmt->bindValue(':t', (string)$targetId, PDO::PARAM_STR);
        $stmt->bindValue(':u', (string)$userId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (string)$row['direction'] : null;
    }

    /**
     * Validate a direction string.
     */
    public static function validDirection(string $direction): bool
    {
        return in_array($direction, self::VALID_DIRS, true);
    }
}
