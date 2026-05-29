<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * TrustScore — append-only fraud/trust score per user.
 *
 * Accumulates signed score deltas for each user into an append-only ledger.
 * The current trust score is the sum of all deltas. Useful for fraud
 * prevention, reputation systems, or any domain that needs an auditable
 * score history.
 *
 * Negative deltas reduce the score (suspicious events); positive deltas
 * increase it (verified identity, good behaviour).
 *
 * ## Usage
 *
 * ```php
 * $ts = new TrustScore($pdo);
 *
 * // Record events
 * $ts->record('user-1', -20, 'login_from_new_country', 'security-bot');
 * $ts->record('user-1', +10, 'email_verified', 'auth-service');
 *
 * // Query
 * $score   = $ts->current('user-1');   // -10
 * $history = $ts->history('user-1');
 * $risky   = $ts->below(0, 50);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE trust_scores (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     delta       INTEGER      NOT NULL,
 *     reason      VARCHAR(255) NOT NULL DEFAULT '',
 *     recorded_by VARCHAR(255) NOT NULL DEFAULT '',
 *     recorded_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class TrustScore
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a trust score delta for a user.
     *
     * @param  int    $delta       Signed integer (positive = good, negative = bad).
     * @param  string $reason      Short description of the event.
     * @param  string $recordedBy  System or user that generated the event.
     * @throws \InvalidArgumentException on empty userId or zero delta.
     */
    public function record(string $userId, int $delta, string $reason = '', string $recordedBy = ''): void
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($delta === 0) {
            throw new \InvalidArgumentException('delta must not be zero.');
        }

        $this->db()->prepare(
            'INSERT INTO trust_scores (user_id, delta, reason, recorded_by)
             VALUES (:uid, :delta, :reason, :by)'
        )->execute([
            ':uid'    => $userId,
            ':delta'  => $delta,
            ':reason' => $reason,
            ':by'     => $recordedBy,
        ]);
    }

    /**
     * Get the current trust score for a user (sum of all deltas).
     *
     * Returns 0 if no events exist.
     */
    public function current(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(delta), 0) FROM trust_scores WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get the full delta history for a user (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $userId, int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, delta, reason, recorded_by, recorded_at
             FROM trust_scores WHERE user_id = :uid
             ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':uid', trim($userId));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List users whose current score is below a threshold.
     *
     * @return list<array{user_id:string, score:int}>
     */
    public function below(int $threshold, int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT user_id, SUM(delta) AS score
             FROM trust_scores
             GROUP BY user_id
             HAVING SUM(delta) < :threshold
             ORDER BY score ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':threshold', $threshold, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        return array_map(
            static fn (array $r): array => ['user_id' => (string)$r['user_id'], 'score' => (int)$r['score']],
            $rows
        );
    }

    /**
     * Reset (delete) all score entries for a user.
     *
     * @return int Number of rows deleted.
     */
    public function reset(string $userId): int
    {
        $stmt = $this->db()->prepare('DELETE FROM trust_scores WHERE user_id = :uid');
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->rowCount();
    }

    /**
     * Delete entries older than a given number of days (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare('DELETE FROM trust_scores WHERE recorded_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
