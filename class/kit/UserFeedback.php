<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Collect general user satisfaction feedback (NPS / star ratings + free text).
 *
 * Unlike ProductReview (which is entity-scoped and moderated), UserFeedback is
 * a lightweight signal-collection tool: any user can submit a numeric score and
 * an optional comment against a named context (page slug, feature name, etc.).
 *
 * ## Usage
 *
 * ```php
 * $uf = new UserFeedback($pdo);
 *
 * // Submit feedback
 * $id = $uf->submit('user-1', 'checkout', 8, 'Smooth experience!');
 *
 * // Aggregate signals
 * $uf->averageScore('checkout');          // e.g. 7.4
 * $uf->countByScore('checkout');          // [8 => 12, 9 => 5, ...]
 * $uf->forUser('user-1');                 // all submissions by this user
 * $uf->recent('checkout', 5);            // 5 most-recent rows
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_feedback (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     context     VARCHAR(255) NOT NULL,
 *     score       TINYINT      NOT NULL,
 *     comment     TEXT         NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class UserFeedback
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    // ── submit ────────────────────────────────────────────────────────────────

    /**
     * Submit feedback from a user.
     *
     * @param  int    $score   Numeric score (typical ranges: 1-5 star, 0-10 NPS).
     * @return int             New row ID.
     * @throws \InvalidArgumentException if userId/context are empty or score < 0.
     */
    public function submit(
        string $userId,
        string $context,
        int $score,
        ?string $comment = null,
    ): int {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty.');
        }

        if ($context === '') {
            throw new \InvalidArgumentException('context must not be empty.');
        }

        if ($score < 0) {
            throw new \InvalidArgumentException('score must be >= 0.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO user_feedback (user_id, context, score, comment)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $context, $score, $comment]);
        return (int)$this->db()->lastInsertId();
    }

    // ── get ───────────────────────────────────────────────────────────────────

    /**
     * Retrieve a single feedback row by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM user_feedback WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── aggregates ────────────────────────────────────────────────────────────

    /**
     * Return the average score for a context, or null if no submissions exist.
     */
    public function averageScore(string $context): ?float
    {
        $stmt = $this->db()->prepare(
            'SELECT AVG(score) FROM user_feedback WHERE context = ?'
        );
        $stmt->execute([$context]);
        $avg = $stmt->fetchColumn();
        return $avg !== false && $avg !== null ? (float)$avg : null;
    }

    /**
     * Return a score → count breakdown for a context (ordered by score asc).
     *
     * @return array<int,int>
     */
    public function countByScore(string $context): array
    {
        $stmt = $this->db()->prepare(
            'SELECT score, COUNT(*) AS cnt
             FROM user_feedback
             WHERE context = ?
             GROUP BY score
             ORDER BY score ASC'
        );
        $stmt->execute([$context]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];

        foreach ($rows as $row) {
            $result[(int)$row['score']] = (int)$row['cnt'];
        }

        return $result;
    }

    // ── queries ───────────────────────────────────────────────────────────────

    /**
     * Return all feedback submitted by a user, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM user_feedback WHERE user_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return the N most-recent feedback rows for a context.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(string $context, int $limit = 10): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM user_feedback WHERE context = ? ORDER BY id DESC LIMIT ?'
        );
        $stmt->execute([$context, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    /**
     * Hard-delete a single feedback row.
     */
    public function remove(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM user_feedback WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hard-delete all feedback for a context (e.g. after a feature is removed).
     *
     * @return int Number of rows deleted.
     */
    public function clearContext(string $context): int
    {
        $stmt = $this->db()->prepare('DELETE FROM user_feedback WHERE context = ?');
        $stmt->execute([$context]);
        return (int)$stmt->rowCount();
    }
}
