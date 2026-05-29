<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * PointBalance — user loyalty/reward points with append-only ledger.
 *
 * Tracks earned, spent, and expired point transactions per user. The running
 * balance is derived by summing all signed deltas. Points are non-negative
 * integers. Spending is guarded to prevent going below zero.
 *
 * Distinct from `CreditLedger` (financial credits, arbitrary amounts, balance
 * guard is configurable) — PointBalance is specifically for loyalty/reward
 * programmes where points are earned through actions and spent on rewards.
 *
 * ## Usage
 *
 * ```php
 * $pb = new PointBalance($pdo);
 *
 * // Earn / spend / expire
 * $pb->earn('user-1', 100, 'purchase', 'order-42');
 * $pb->spend('user-1', 50,  'redeem',   'reward-7');
 * $pb->expire('user-1', 20, 'monthly-expiry');
 *
 * // Query
 * $balance = $pb->balance('user-1');       // 30
 * $history = $pb->history('user-1', 20, 0);
 * $earned  = $pb->totalEarned('user-1');   // 100
 * $spent   = $pb->totalSpent('user-1');    // 50
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE point_ledger (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     delta       INTEGER      NOT NULL,
 *     reason      VARCHAR(100) NOT NULL,
 *     reference   VARCHAR(255) NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class PointBalance
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Credit points to a user (positive delta).
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on invalid userId/reason or non-positive amount.
     */
    public function earn(string $userId, int $points, string $reason, ?string $reference = null): int
    {
        if ($points <= 0) {
            throw new \InvalidArgumentException('points must be > 0.');
        }
        return $this->append($userId, $points, $reason, $reference);
    }

    /**
     * Debit points from a user (negative delta). Guards against going below zero.
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on invalid arguments.
     * @throws \RuntimeException if insufficient balance.
     */
    public function spend(string $userId, int $points, string $reason, ?string $reference = null): int
    {
        if ($points <= 0) {
            throw new \InvalidArgumentException('points must be > 0.');
        }
        if ($this->balance($userId) < $points) {
            throw new \RuntimeException("Insufficient points for user {$userId}.");
        }
        return $this->append($userId, -$points, $reason, $reference);
    }

    /**
     * Expire points (negative delta) — does NOT guard against going below zero.
     * Used by batch expiry jobs where balance could go negative due to timing.
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on invalid arguments.
     */
    public function expire(string $userId, int $points, string $reason, ?string $reference = null): int
    {
        if ($points <= 0) {
            throw new \InvalidArgumentException('points must be > 0.');
        }
        return $this->append($userId, -$points, $reason, $reference);
    }

    /**
     * Return the current point balance (sum of all deltas). Returns 0 if no entries.
     */
    public function balance(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(delta), 0) FROM point_ledger WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Return total points ever earned (sum of positive deltas).
     */
    public function totalEarned(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(delta), 0) FROM point_ledger WHERE user_id = :uid AND delta > 0'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Return total points ever spent (absolute sum of negative deltas).
     */
    public function totalSpent(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COALESCE(SUM(delta), 0) FROM point_ledger WHERE user_id = :uid AND delta < 0'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return abs((int)$stmt->fetchColumn());
    }

    /**
     * List ledger entries for a user (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM point_ledger
             WHERE user_id = :uid
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':uid', trim($userId));
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Find a single ledger entry by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM point_ledger WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function append(string $userId, int $delta, string $reason, ?string $reference): int
    {
        $userId = trim($userId);
        $reason = trim($reason);
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty.');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('reason must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO point_ledger (user_id, delta, reason, reference)
             VALUES (:uid, :delta, :reason, :ref)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':delta'  => $delta,
            ':reason' => $reason,
            ':ref'    => $reference,
        ]);
        return (int)$this->db()->lastInsertId();
    }
}
