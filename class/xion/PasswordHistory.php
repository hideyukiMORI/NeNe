<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Password history — prevent users from reusing recent passwords.
 *
 * Stores the last N password hashes per user and checks whether a proposed
 * new password matches any of them. Uses `password_verify()` for comparison
 * so any hash algorithm supported by PHP is accepted.
 *
 * ## Usage
 *
 * ```php
 * $ph = new PasswordHistory($pdo);
 *
 * // After a successful password change, record the OLD hash
 * $ph->push('user-1', $oldHash);
 *
 * // Before accepting a new password, check it
 * if ($ph->wasUsed('user-1', $newPlainText)) {
 *     throw new \RuntimeException('Cannot reuse a recent password.');
 * }
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE password_history (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     hash        VARCHAR(255) NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class PasswordHistory
{
    /** Number of past hashes to retain and check by default. */
    private const DEFAULT_DEPTH = 5;

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly int $depth = self::DEFAULT_DEPTH
    ) {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Store a password hash in the user's history.
     *
     * After inserting, old entries beyond `$depth` are pruned automatically.
     *
     * @param string $userId   User whose history to update.
     * @param string $hash     bcrypt/argon/etc. hash of the password to record.
     * @throws \InvalidArgumentException if hash is empty.
     */
    public function push(string $userId, string $hash): void
    {
        $hash = trim($hash);
        if ($hash === '') {
            throw new \InvalidArgumentException('Password hash must not be empty.');
        }

        $db = $this->db();
        $db->prepare(
            'INSERT INTO password_history (user_id, hash) VALUES (:user, :hash)'
        )->execute([':user' => $userId, ':hash' => $hash]);

        $this->prune($db, $userId);
    }

    /**
     * Check if a plain-text password matches any of the stored hashes.
     *
     * Uses `password_verify()` so it works with bcrypt, argon2i, argon2id.
     *
     * @param string $userId        User to check.
     * @param string $plainPassword Plain-text password to test.
     * @return bool True if the password was previously used.
     */
    public function wasUsed(string $userId, string $plainPassword): bool
    {
        $hashes = $this->recent($userId);
        foreach ($hashes as $hash) {
            if (password_verify($plainPassword, $hash)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the stored hashes for a user (newest first, up to `$depth`).
     *
     * @return list<string>
     */
    public function recent(string $userId): array
    {
        $depth = max(1, $this->depth);
        $stmt  = $this->db()->prepare(
            "SELECT hash FROM password_history
             WHERE user_id = :user
             ORDER BY id DESC
             LIMIT {$depth}"
        );
        $stmt->execute([':user' => $userId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'hash');
    }

    /**
     * Count how many hashes are stored for a user.
     */
    public function count(string $userId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM password_history WHERE user_id = :user');
        $stmt->execute([':user' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Clear all password history for a user (e.g. on account deletion).
     *
     * @return int Number of rows deleted.
     */
    public function clear(string $userId): int
    {
        $stmt = $this->db()->prepare('DELETE FROM password_history WHERE user_id = :user');
        $stmt->execute([':user' => $userId]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * Delete oldest entries beyond the configured depth.
     */
    private function prune(PDO $db, string $userId): void
    {
        $depth = max(1, $this->depth);

        // Find the ID of the Nth-newest row
        $stmt = $db->prepare(
            "SELECT id FROM password_history
             WHERE user_id = :user
             ORDER BY id DESC
             LIMIT 1 OFFSET {$depth}"
        );
        $stmt->execute([':user' => $userId]);
        $cutoffId = $stmt->fetchColumn();

        if ($cutoffId !== false) {
            $db->prepare(
                'DELETE FROM password_history WHERE user_id = :user AND id <= :cutoff'
            )->execute([':user' => $userId, ':cutoff' => (int)$cutoffId]);
        }
    }
}
