<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Score-based leaderboard with best-score retention, ranking, and personal rank lookup.
 *
 * One score row per user per leaderboard (`UNIQUE (leaderboard_id, user_id)`).
 * Submit always keeps the personal best — lower scores are silently discarded.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE leaderboard_scores (
 *     id             INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     leaderboard_id VARCHAR(255) NOT NULL,
 *     user_id        VARCHAR(255) NOT NULL,
 *     score          INTEGER      NOT NULL,
 *     submitted_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (leaderboard_id, user_id)
 * );
 * CREATE INDEX idx_lb_scores ON leaderboard_scores (leaderboard_id, score DESC);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $lb = new Leaderboard();
 *
 * // Submit a score (only kept if it's a new personal best)
 * $result = $lb->submit('game:tetris', 'user:1', 42000);
 * // $result['is_best'] is true if this is a new personal best
 *
 * // Get top rankings
 * $rankings = $lb->rankings('game:tetris', limit: 10);
 *
 * // Get a user's rank and score
 * $rank = $lb->rank('game:tetris', 'user:1');
 * // ['rank' => 3, 'score' => 42000] or null if no score
 *
 * // Remove a score
 * $lb->remove('game:tetris', 'user:1');
 * ```
 */
final class Leaderboard
{
    private const TABLE     = 'leaderboard_scores';
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Submit a score. Only kept if it exceeds the user's current personal best.
     *
     * @param  string|int $leaderboardId Leaderboard identifier.
     * @param  string|int $userId        User identifier.
     * @param  int        $score         Score value (integer only — no floats).
     * @return array{is_best: bool, score: int} Whether this is a new best and current best score.
     */
    public function submit(string|int $leaderboardId, string|int $userId, int $score): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $lbId = (string)$leaderboardId;
        $uId  = (string)$userId;
        $now  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Check existing score
        $stmt = $db->prepare(
            'SELECT id, score FROM ' . $this->table .
            ' WHERE leaderboard_id = :l AND user_id = :u LIMIT 1'
        );
        $stmt->bindValue(':l', $lbId, PDO::PARAM_STR);
        $stmt->bindValue(':u', $uId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            // First submission — INSERT
            $stmt = $db->prepare(
                'INSERT INTO ' . $this->table .
                ' (leaderboard_id, user_id, score, submitted_at)' .
                ' VALUES (:l, :u, :s, :t)'
            );
            $stmt->bindValue(':l', $lbId, PDO::PARAM_STR);
            $stmt->bindValue(':u', $uId, PDO::PARAM_STR);
            $stmt->bindValue(':s', $score, PDO::PARAM_INT);
            $stmt->bindValue(':t', $now, PDO::PARAM_STR);
            $stmt->execute();
            return ['is_best' => true, 'score' => $score];
        }

        $currentBest = (int)$row['score'];
        if ($score > $currentBest) {
            // New personal best — UPDATE
            $stmt = $db->prepare(
                'UPDATE ' . $this->table .
                ' SET score = :s, submitted_at = :t' .
                ' WHERE leaderboard_id = :l AND user_id = :u'
            );
            $stmt->bindValue(':s', $score, PDO::PARAM_INT);
            $stmt->bindValue(':t', $now, PDO::PARAM_STR);
            $stmt->bindValue(':l', $lbId, PDO::PARAM_STR);
            $stmt->bindValue(':u', $uId, PDO::PARAM_STR);
            $stmt->execute();
            return ['is_best' => true, 'score' => $score];
        }

        // Score is not a new best — no change
        return ['is_best' => false, 'score' => $currentBest];
    }

    /**
     * Get the top N rankings for a leaderboard, highest score first.
     *
     * Ties are broken by submission time (earlier submission wins same rank).
     * `$limit` is clamped to 1–100.
     *
     * @param  string|int $leaderboardId Leaderboard identifier.
     * @param  int        $limit         Maximum entries (1–100, default 10).
     * @return list<array{rank: int, user_id: string, score: int, submitted_at: string}>
     */
    public function rankings(string|int $leaderboardId, int $limit = 10): array
    {
        $db    = $this->db ?? PdoConnection::getInstance();
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $stmt = $db->prepare(
            'SELECT user_id, score, submitted_at FROM ' . $this->table .
            ' WHERE leaderboard_id = :l ORDER BY score DESC, submitted_at ASC LIMIT :n'
        );
        $stmt->bindValue(':l', (string)$leaderboardId, PDO::PARAM_STR);
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $rank    = 1;
        $result  = [];
        $prevScore = null;
        $tiedRank  = 1;

        foreach ($rows as $i => $row) {
            $s = (int)$row['score'];
            if ($prevScore !== null && $s < $prevScore) {
                $tiedRank = $i + 1; // rank advances only when score changes
            }
            $result[] = [
                'rank'         => $tiedRank,
                'user_id'      => (string)$row['user_id'],
                'score'        => $s,
                'submitted_at' => (string)$row['submitted_at'],
            ];
            $prevScore = $s;
        }

        return $result;
    }

    /**
     * Get a user's rank and score on a leaderboard.
     *
     * Rank is calculated as the number of users with a strictly higher score + 1
     * (tied users share the same rank).
     *
     * @param  string|int $leaderboardId Leaderboard identifier.
     * @param  string|int $userId        User identifier.
     * @return array{rank: int, score: int}|null Null if user has no score.
     */
    public function rank(string|int $leaderboardId, string|int $userId): ?array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $lbId = (string)$leaderboardId;
        $uId  = (string)$userId;

        // Get user's current score
        $stmt = $db->prepare(
            'SELECT score FROM ' . $this->table .
            ' WHERE leaderboard_id = :l AND user_id = :u LIMIT 1'
        );
        $stmt->bindValue(':l', $lbId, PDO::PARAM_STR);
        $stmt->bindValue(':u', $uId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $score = (int)$row['score'];

        // Count users with strictly higher score
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM ' . $this->table .
            ' WHERE leaderboard_id = :l AND score > :s'
        );
        $stmt->bindValue(':l', $lbId, PDO::PARAM_STR);
        $stmt->bindValue(':s', $score, PDO::PARAM_INT);
        $stmt->execute();
        $above = (int)$stmt->fetchColumn();

        return ['rank' => $above + 1, 'score' => $score];
    }

    /**
     * Remove a user's score from a leaderboard.
     *
     * Returns true if a row was removed, false if the user had no score.
     *
     * @param string|int $leaderboardId Leaderboard identifier.
     * @param string|int $userId        User identifier.
     */
    public function remove(string|int $leaderboardId, string|int $userId): bool
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'DELETE FROM ' . $this->table .
            ' WHERE leaderboard_id = :l AND user_id = :u'
        );
        $stmt->bindValue(':l', (string)$leaderboardId, PDO::PARAM_STR);
        $stmt->bindValue(':u', (string)$userId, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
}
