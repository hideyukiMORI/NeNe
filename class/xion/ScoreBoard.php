<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ScoreBoard — high-score table with personal-best and period tracking.
 *
 * Records every score submission and derives high-score tables from the raw
 * events. Supports multiple categories (game modes, difficulty levels, etc.)
 * and period-scoped queries (all-time, daily, weekly, monthly).
 *
 * Personal bests are derived on-the-fly from MAX(score), so no denormalised
 * cache is needed. The leaderboard query returns each player's single best
 * score per category, ranked descending.
 *
 * ## Usage
 *
 * ```php
 * $sb = new ScoreBoard($pdo);
 *
 * // Submit a score
 * $sb->submit('user-1', 'arcade', 15000);
 *
 * // Top 10 for a category
 * $top = $sb->top('arcade', 10);
 *
 * // Personal best
 * $best = $sb->personalBest('user-1', 'arcade');
 *
 * // Top scores this week
 * $weekly = $sb->top('arcade', 10, date('Y-W'));
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE score_board (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     category   VARCHAR(100) NOT NULL,
 *     score      INTEGER      NOT NULL,
 *     period     VARCHAR(20)  NOT NULL DEFAULT '',
 *     meta       TEXT         NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ScoreBoard
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Submit a score for a player in a category.
     *
     * @param array<string,mixed>|null $meta Optional extra data (level, duration, etc.).
     * @return int New row ID.
     * @throws \InvalidArgumentException on empty userId/category.
     */
    public function submit(string $userId, string $category, int $score, string $period = '', ?array $meta = null): int
    {
        $userId   = trim($userId);
        $category = trim($category);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($category === '') {
            throw new \InvalidArgumentException('category must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO score_board (user_id, category, score, period, meta, created_at)
             VALUES (:uid, :cat, :score, :period, :meta, :now)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':cat'    => $category,
            ':score'  => $score,
            ':period' => $period,
            ':meta'   => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return the top-N leaderboard for a category.
     *
     * Each player appears at most once (their personal best for that category
     * and period). Results are ordered best score descending.
     *
     * @return list<array<string,mixed>>  Each row: user_id, category, best_score, period.
     */
    public function top(string $category, int $limit = 10, string $period = ''): array
    {
        $stmt = $this->db()->prepare(
            'SELECT user_id, category, MAX(score) AS best_score, period
             FROM score_board
             WHERE category = :cat AND period = :period
             GROUP BY user_id
             ORDER BY best_score DESC, MIN(id) ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':cat', trim($category));
        $stmt->bindValue(':period', $period);
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return a player's personal best score for a category (and optional period).
     *
     * Returns null if no scores have been submitted.
     */
    public function personalBest(string $userId, string $category, string $period = ''): ?int
    {
        $stmt = $this->db()->prepare(
            'SELECT MAX(score) FROM score_board
             WHERE user_id = :uid AND category = :cat AND period = :period'
        );
        $stmt->execute([':uid' => trim($userId), ':cat' => trim($category), ':period' => $period]);
        $val = $stmt->fetchColumn();
        return $val !== null && $val !== false ? (int)$val : null;
    }

    /**
     * Return all score submissions for a player in a category, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $userId, string $category, int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM score_board
             WHERE user_id = :uid AND category = :cat
             ORDER BY created_at DESC, id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':uid', trim($userId));
        $stmt->bindValue(':cat', trim($category));
        $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return a player's rank (1-based) for a category.
     *
     * Rank is determined by how many distinct players have a higher best score.
     * Returns null if the player has no scores.
     */
    public function rank(string $userId, string $category, string $period = ''): ?int
    {
        $best = $this->personalBest($userId, $category, $period);
        if ($best === null) {
            return null;
        }

        $stmt = $this->db()->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM score_board
             WHERE category = :cat AND period = :period AND score > :best'
        );
        $stmt->execute([':cat' => trim($category), ':period' => $period, ':best' => $best]);
        return (int)$stmt->fetchColumn() + 1;
    }

    /**
     * Return the total number of score submissions for a category.
     */
    public function count(string $category, string $period = ''): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM score_board WHERE category = :cat AND period = :period'
        );
        $stmt->execute([':cat' => trim($category), ':period' => $period]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete all scores for a player in a category (and optional period).
     *
     * @return int Number of rows deleted.
     */
    public function reset(string $userId, string $category, string $period = ''): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM score_board WHERE user_id = :uid AND category = :cat AND period = :period'
        );
        $stmt->execute([':uid' => trim($userId), ':cat' => trim($category), ':period' => $period]);
        return $stmt->rowCount();
    }

    /**
     * Delete score rows older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare('DELETE FROM score_board WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
