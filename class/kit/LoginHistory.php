<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * LoginHistory — append-only log of login attempts with IP, user-agent, and result.
 *
 * Records each login attempt (success or failure) for audit, security analysis,
 * and suspicious activity detection. All entries are immutable once written.
 *
 * ## Usage
 *
 * ```php
 * $lh = new LoginHistory($pdo);
 *
 * // Record a successful login
 * $lh->record('user-1', true, '203.0.113.1', 'Mozilla/5.0 ...');
 *
 * // Record a failed attempt
 * $lh->record('user-1', false, '203.0.113.1', '', 'bad_password');
 *
 * // Fetch recent entries
 * $lh->recent('user-1', 10);
 *
 * // Count failures since last success
 * $lh->consecutiveFailures('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE login_history (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     success    TINYINT(1)   NOT NULL DEFAULT 0,
 *     ip_address VARCHAR(45)  NOT NULL DEFAULT '',
 *     user_agent TEXT         NOT NULL DEFAULT '',
 *     reason     VARCHAR(100) NOT NULL DEFAULT '',
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class LoginHistory
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a login attempt.
     *
     * @param  bool   $success    True = successful login; false = failed attempt.
     * @param  string $ipAddress  Remote IP address (IPv4 or IPv6).
     * @param  string $userAgent  HTTP User-Agent string.
     * @param  string $reason     Failure reason code (e.g. 'bad_password', 'account_locked').
     * @return int  The new record ID.
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function record(
        string $userId,
        bool $success,
        string $ipAddress = '',
        string $userAgent = '',
        string $reason = ''
    ): int {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'INSERT INTO login_history (user_id, success, ip_address, user_agent, reason)
             VALUES (:uid, :ok, :ip, :ua, :reason)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':ok'     => $success ? 1 : 0,
            ':ip'     => $ipAddress,
            ':ua'     => $userAgent,
            ':reason' => $reason,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Get the most recent login attempts for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(string $userId, int $limit = 20): array
    {
        $userId = $this->validateUserId($userId);
        $limit  = max(1, $limit);
        $stmt   = $this->db()->prepare(
            "SELECT id, user_id, success, ip_address, user_agent, reason, created_at
             FROM login_history
             WHERE user_id = :uid
             ORDER BY id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get the most recent successful login for a user.
     *
     * @return array<string,mixed>|null
     */
    public function lastSuccess(string $userId): ?array
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT id, user_id, ip_address, user_agent, created_at
             FROM login_history
             WHERE user_id = :uid AND success = 1
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the most recent failed login attempt for a user.
     *
     * @return array<string,mixed>|null
     */
    public function lastFailure(string $userId): ?array
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT id, user_id, ip_address, reason, created_at
             FROM login_history
             WHERE user_id = :uid AND success = 0
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Count the number of consecutive failures since the last successful login.
     *
     * Returns the total failure count if the user has never logged in successfully.
     */
    public function consecutiveFailures(string $userId): int
    {
        $userId = $this->validateUserId($userId);

        // Find the id of the most recent success
        $stmt = $this->db()->prepare(
            'SELECT id FROM login_history WHERE user_id = :uid AND success = 1 ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId]);
        $lastSuccessId = $stmt->fetchColumn();

        if ($lastSuccessId !== false) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM login_history
                 WHERE user_id = :uid AND success = 0 AND id > :sid'
            );
            $stmt->execute([':uid' => $userId, ':sid' => (int)$lastSuccessId]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM login_history WHERE user_id = :uid AND success = 0'
            );
            $stmt->execute([':uid' => $userId]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count all login attempts for a user (optional: filter by success/failure).
     *
     * @param bool|null $success null = count all; true = successes; false = failures.
     */
    public function count(string $userId, ?bool $success = null): int
    {
        $userId = $this->validateUserId($userId);
        if ($success === null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM login_history WHERE user_id = :uid'
            );
            $stmt->execute([':uid' => $userId]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM login_history WHERE user_id = :uid AND success = :ok'
            );
            $stmt->execute([':uid' => $userId, ':ok' => $success ? 1 : 0]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Purge history older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM login_history WHERE created_at < :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
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
}
