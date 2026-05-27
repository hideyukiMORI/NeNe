<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * LeaderBoard — named scoreboards with upsert, ranking, and windowed queries.
 *
 * Multiple named leaderboards are supported (e.g. 'weekly', 'alltime', 'arena-1').
 * Scores can be set (absolute) or incremented. Rankings are 1-based dense rank.
 *
 * ## Usage
 *
 * ```php
 * $lb = new LeaderBoard($pdo);
 *
 * // Set or update a score
 * $lb->setScore('alltime', 'user-1', 1500);
 *
 * // Increment score
 * $lb->increment('alltime', 'user-1', 50);
 *
 * // Get top N
 * $lb->top('alltime', 10);
 *
 * // Get a user's rank and score
 * $lb->rank('alltime', 'user-1'); // ['rank' => 3, 'score' => 1500]
 *
 * // Get score
 * $lb->score('alltime', 'user-1'); // 1500
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE leaderboard (
 *     id             INTEGER PRIMARY KEY AUTOINCREMENT,
 *     board_name     VARCHAR(100) NOT NULL,
 *     user_id        VARCHAR(255) NOT NULL,
 *     score          BIGINT       NOT NULL DEFAULT 0,
 *     updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (board_name, user_id)
 * );
 * ```
 */
final class LeaderBoard
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set a user's score (absolute value).
     *
     * Creates the entry if it doesn't exist, updates it if it does.
     *
     * @throws \InvalidArgumentException if board_name or user_id is empty.
     */
    public function setScore(string $boardName, string $userId, int $score): void
    {
        [$boardName, $userId] = $this->normalise($boardName, $userId);
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO leaderboard (board_name, user_id, score)
                 VALUES (:board, :uid, :score)
                 ON CONFLICT (board_name, user_id)
                 DO UPDATE SET score = excluded.score, updated_at = CURRENT_TIMESTAMP'
            )->execute([':board' => $boardName, ':uid' => $userId, ':score' => $score]);
        } else {
            $db->prepare(
                'INSERT INTO leaderboard (board_name, user_id, score)
                 VALUES (:board, :uid, :score)
                 ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = CURRENT_TIMESTAMP'
            )->execute([':board' => $boardName, ':uid' => $userId, ':score' => $score]);
        }
    }

    /**
     * Increment a user's score by a delta (default 1). Creates the entry if needed.
     *
     * @param int $delta Can be negative to decrement.
     */
    public function increment(string $boardName, string $userId, int $delta = 1): void
    {
        [$boardName, $userId] = $this->normalise($boardName, $userId);
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO leaderboard (board_name, user_id, score)
                 VALUES (:board, :uid, :delta)
                 ON CONFLICT (board_name, user_id)
                 DO UPDATE SET score = score + excluded.score, updated_at = CURRENT_TIMESTAMP'
            )->execute([':board' => $boardName, ':uid' => $userId, ':delta' => $delta]);
        } else {
            $db->prepare(
                'INSERT INTO leaderboard (board_name, user_id, score)
                 VALUES (:board, :uid, :delta)
                 ON DUPLICATE KEY UPDATE score = score + VALUES(score), updated_at = CURRENT_TIMESTAMP'
            )->execute([':board' => $boardName, ':uid' => $userId, ':delta' => $delta]);
        }
    }

    /**
     * Get the top N users on a board (highest score first).
     *
     * @return list<array{rank: int, user_id: string, score: int}>
     */
    public function top(string $boardName, int $limit = 10): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT user_id, score FROM leaderboard
             WHERE board_name = :board
             ORDER BY score DESC, updated_at ASC
             LIMIT {$limit}"
        );
        $stmt->execute([':board' => $boardName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out  = [];
        foreach ($rows as $i => $row) {
            $out[] = ['rank' => $i + 1, 'user_id' => (string)$row['user_id'], 'score' => (int)$row['score']];
        }
        return $out;
    }

    /**
     * Get a user's current score.
     *
     * @return int|null  null if the user has no entry.
     */
    public function score(string $boardName, string $userId): ?int
    {
        [$boardName, $userId] = $this->normalise($boardName, $userId);
        $stmt = $this->db()->prepare(
            'SELECT score FROM leaderboard WHERE board_name = :board AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':board' => $boardName, ':uid' => $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * Get a user's rank and score on a board.
     *
     * Rank is 1-based; users with higher scores rank lower numbers.
     * Ties are broken by earliest updated_at (longest-held score wins tie).
     *
     * @return array{rank: int, score: int}|null  null if not on board.
     */
    public function rank(string $boardName, string $userId): ?array
    {
        [$boardName, $userId] = $this->normalise($boardName, $userId);

        // Count how many users have a strictly higher score, or same score but earlier update
        $stmt = $this->db()->prepare(
            'SELECT score, updated_at FROM leaderboard
             WHERE board_name = :board AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':board' => $boardName, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $userScore  = (int)$row['score'];
        $updatedAt  = (string)$row['updated_at'];

        $stmt2 = $this->db()->prepare(
            'SELECT COUNT(*) + 1 FROM leaderboard
             WHERE board_name = :board
               AND (score > :score
                    OR (score = :score2 AND updated_at < :ts))'
        );
        $stmt2->execute([
            ':board'  => $boardName,
            ':score'  => $userScore,
            ':score2' => $userScore,
            ':ts'     => $updatedAt,
        ]);
        $rank = (int)$stmt2->fetchColumn();

        return ['rank' => $rank, 'score' => $userScore];
    }

    /**
     * Remove a user from a leaderboard.
     *
     * @return bool True if an entry was deleted.
     */
    public function remove(string $boardName, string $userId): bool
    {
        [$boardName, $userId] = $this->normalise($boardName, $userId);
        $stmt = $this->db()->prepare(
            'DELETE FROM leaderboard WHERE board_name = :board AND user_id = :uid'
        );
        $stmt->execute([':board' => $boardName, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Clear all entries from a leaderboard.
     *
     * @return int Number of rows deleted.
     */
    public function clear(string $boardName): int
    {
        $stmt = $this->db()->prepare('DELETE FROM leaderboard WHERE board_name = :board');
        $stmt->execute([':board' => $boardName]);
        return $stmt->rowCount();
    }

    /**
     * Count the number of users on a leaderboard.
     */
    public function count(string $boardName): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM leaderboard WHERE board_name = :board');
        $stmt->execute([':board' => $boardName]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $boardName, string $userId): array
    {
        $boardName = trim($boardName);
        $userId    = trim($userId);
        if ($boardName === '') {
            throw new \InvalidArgumentException('board_name must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$boardName, $userId];
    }
}
