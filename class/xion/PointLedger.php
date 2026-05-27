<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Append-only point / loyalty system with negative-balance prevention.
 *
 * Points are stored as signed `delta` entries. The current balance is the
 * sum of all deltas for a user. The ledger is never modified — only appended.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE point_ledger (
 *     id          INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     delta       INTEGER      NOT NULL,
 *     description VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_point_ledger_user ON point_ledger (user_id);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $ledger = new PointLedger($pdo);
 *
 * $ledger->earn('user:1', 100, 'Purchase reward');   // +100
 * $ledger->earn('user:1', 50,  'Referral bonus');    // +50
 * $ledger->balance('user:1');                        // 150
 *
 * $ledger->spend('user:1', 30, 'Redeem discount');   // true  (balance: 120)
 * $ledger->spend('user:1', 200, 'Too many');         // false (insufficient)
 * $ledger->balance('user:1');                        // 120
 *
 * $ledger->history('user:1');  // most recent first
 * ```
 */
final class PointLedger
{
    private const TABLE = 'point_ledger';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Earn points for a user.
     *
     * @param  string $userId      Application-level user identifier.
     * @param  int    $points      Positive number of points to add.
     * @param  string $description Optional description for the ledger entry.
     * @return int New ledger entry ID.
     * @throws \InvalidArgumentException if $points is not positive.
     */
    public function earn(string $userId, int $points, string $description = ''): int
    {
        if ($points <= 0) {
            throw new \InvalidArgumentException(
                "Points to earn must be positive, got {$points}."
            );
        }

        $db        = $this->db ?? PdoConnection::getInstance();
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (user_id, delta, description, created_at)' .
            ' VALUES (:u, :d, :desc, :t)'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':d', $points, PDO::PARAM_INT);
        $stmt->bindValue(':desc', $description, PDO::PARAM_STR);
        $stmt->bindValue(':t', $createdAt, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$db->lastInsertId();
    }

    /**
     * Spend points. Returns false if the user has insufficient balance.
     *
     * The check and insert are wrapped in a transaction to prevent races.
     *
     * @param  string $userId      Application-level user identifier.
     * @param  int    $points      Positive number of points to deduct.
     * @param  string $description Optional description for the ledger entry.
     * @return bool True if spent successfully, false if balance would go negative.
     * @throws \InvalidArgumentException if $points is not positive.
     */
    public function spend(string $userId, int $points, string $description = ''): bool
    {
        if ($points <= 0) {
            throw new \InvalidArgumentException(
                "Points to spend must be positive, got {$points}."
            );
        }

        $db = $this->db ?? PdoConnection::getInstance();
        $db->beginTransaction();

        try {
            $current = $this->balanceQuery($userId, $db);
            if ($current < $points) {
                $db->rollBack();
                return false;
            }

            $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $stmt = $db->prepare(
                'INSERT INTO ' . $this->table .
                ' (user_id, delta, description, created_at)' .
                ' VALUES (:u, :d, :desc, :t)'
            );
            $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
            $stmt->bindValue(':d', -$points, PDO::PARAM_INT);
            $stmt->bindValue(':desc', $description, PDO::PARAM_STR);
            $stmt->bindValue(':t', $createdAt, PDO::PARAM_STR);
            $stmt->execute();

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Get the current point balance for a user.
     *
     * Returns 0 if the user has no ledger entries.
     *
     * @param string $userId Application-level user identifier.
     */
    public function balance(string $userId): int
    {
        return $this->balanceQuery($userId, $this->db ?? PdoConnection::getInstance());
    }

    /**
     * Get recent ledger entries for a user, newest first.
     *
     * @param  string $userId Application-level user identifier.
     * @param  int    $limit  Maximum number of entries to return (1–100).
     * @return list<array{id:int,delta:int,description:string,created_at:string}>
     */
    public function history(string $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, delta, description, created_at' .
            ' FROM ' . $this->table .
            ' WHERE user_id = :u' .
            ' ORDER BY id DESC' .
            ' LIMIT :lim'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'id'          => (int)$r['id'],
            'delta'       => (int)$r['delta'],
            'description' => (string)$r['description'],
            'created_at'  => (string)$r['created_at'],
        ], $rows);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function balanceQuery(string $userId, PDO $db): int
    {
        $stmt = $db->prepare(
            'SELECT COALESCE(SUM(delta), 0) FROM ' . $this->table . ' WHERE user_id = :u'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }
}
