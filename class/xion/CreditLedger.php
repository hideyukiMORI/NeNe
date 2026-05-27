<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * CreditLedger — append-only double-entry credit/debit ledger per user.
 *
 * Tracks a running credit balance for each user. All entries are immutable
 * once written. Positive amounts are credits; negative amounts are debits.
 * Balance is always derived from the full transaction history.
 *
 * ## Usage
 *
 * ```php
 * $cl = new CreditLedger($pdo);
 *
 * // Credit (add credits)
 * $cl->credit('user-1', 100, 'referral_bonus');
 *
 * // Debit (spend credits); throws if insufficient balance
 * $cl->debit('user-1', 30, 'purchase');
 *
 * // Check balance
 * $cl->balance('user-1'); // 70
 *
 * // Transaction history
 * $cl->history('user-1', 10);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE credit_ledger (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     amount      INT          NOT NULL,
 *     description VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class CreditLedger
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add credits to a user's balance.
     *
     * @param  int    $amount      Positive integer number of credits to add.
     * @param  string $description Reason for the credit.
     * @return int The new ledger entry ID.
     * @throws \InvalidArgumentException if user_id is empty or amount <= 0.
     */
    public function credit(string $userId, int $amount, string $description = ''): int
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('credit amount must be greater than zero.');
        }
        return $this->append($userId, $amount, $description);
    }

    /**
     * Deduct credits from a user's balance.
     *
     * @param  int    $amount      Positive integer number of credits to deduct.
     * @param  string $description Reason for the debit.
     * @return int The new ledger entry ID.
     * @throws \InvalidArgumentException if amount <= 0 or insufficient balance.
     */
    public function debit(string $userId, int $amount, string $description = ''): int
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('debit amount must be greater than zero.');
        }
        $userId = $this->validateUserId($userId);
        if ($this->balance($userId) < $amount) {
            throw new \RuntimeException("Insufficient credits for user '{$userId}'.");
        }
        return $this->append($userId, -$amount, $description);
    }

    /**
     * Get the current balance for a user.
     *
     * Returns 0 if the user has no ledger entries.
     */
    public function balance(string $userId): int
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare('SELECT SUM(amount) FROM credit_ledger WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Get the most recent ledger entries for a user.
     *
     * @return list<array{id: int, amount: int, description: string, created_at: string}>
     */
    public function history(string $userId, int $limit = 20): array
    {
        $userId = $this->validateUserId($userId);
        $limit  = max(1, $limit);
        $stmt   = $this->db()->prepare(
            "SELECT id, amount, description, created_at
             FROM credit_ledger
             WHERE user_id = :uid
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':uid' => $userId]);
        return array_map(
            static fn (array $r) => [
                'id'          => (int)$r['id'],
                'amount'      => (int)$r['amount'],
                'description' => (string)$r['description'],
                'created_at'  => (string)$r['created_at'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Check whether a user has enough credits.
     */
    public function hasEnough(string $userId, int $amount): bool
    {
        return $this->balance($userId) >= $amount;
    }

    /**
     * Count the number of ledger entries for a user.
     */
    public function count(string $userId): int
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare('SELECT COUNT(*) FROM credit_ledger WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }

    private function append(string $userId, int $amount, string $description): int
    {
        $userId = $this->validateUserId($userId);
        $db     = $this->db();

        $db->prepare(
            'INSERT INTO credit_ledger (user_id, amount, description) VALUES (:uid, :amount, :desc)'
        )->execute([':uid' => $userId, ':amount' => $amount, ':desc' => $description]);
        return (int)$db->lastInsertId();
    }
}
