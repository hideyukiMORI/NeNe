<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * DB-backed tracker for consecutive failed login attempts.
 *
 * Prevents brute-force attacks by counting per-user failures and
 * refusing further attempts once a threshold is reached.
 *
 * Schema (one-time migration):
 *   CREATE TABLE login_attempts (
 *       user_id     VARCHAR(255) PRIMARY KEY,
 *       failures    INT          NOT NULL DEFAULT 0,
 *       locked_at   DATETIME     NULL,
 *       updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   );
 *
 * Typical controller usage:
 *   $tracker = new LoginAttemptTracker();
 *   if ($tracker->isLocked($userId)) {
 *       // return 423 Locked
 *   }
 *   if (!$auth->verify($userId, $password)) {
 *       $tracker->recordFailure($userId);
 *       // return 401
 *   }
 *   $tracker->reset($userId);
 *   // proceed with session
 */
final class LoginAttemptTracker
{
    private const DEFAULT_MAX_FAILURES = 5;
    private const DEFAULT_TABLE = 'login_attempts';

    private PDO $db;
    private int $maxFailures;
    private string $table;

    public function __construct(
        ?PDO $db = null,
        int $maxFailures = self::DEFAULT_MAX_FAILURES,
        string $table = self::DEFAULT_TABLE,
    ) {
        $this->db          = $db ?? PdoConnection::getInstance();
        $this->maxFailures = $maxFailures;
        $this->table       = $table;
    }

    /**
     * Record a failed login attempt for the given user.
     *
     * Increments the failure counter. If the counter reaches
     * $maxFailures after this increment, sets locked_at to NOW().
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE (MySQL) or
     * INSERT OR REPLACE (SQLite) for atomic upsert.
     *
     * @return int The new failure count after this increment.
     */
    public function recordFailure(string $userId): int
    {
        // Read current state
        $stmt = $this->db->prepare(
            "SELECT failures FROM {$this->table} WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $newCount = ($row !== false ? (int) $row['failures'] : 0) + 1;
        $lockedAt = ($newCount >= $this->maxFailures) ? date('Y-m-d H:i:s') : null;

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = "INSERT OR REPLACE INTO {$this->table} (user_id, failures, locked_at, updated_at)
                    VALUES (?, ?, ?, datetime('now'))";
        } else {
            $sql = "INSERT INTO {$this->table} (user_id, failures, locked_at, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        failures   = VALUES(failures),
                        locked_at  = VALUES(locked_at),
                        updated_at = NOW()";
        }
        $this->db->prepare($sql)->execute([$userId, $newCount, $lockedAt]);
        return $newCount;
    }

    /**
     * Check whether the account is currently locked.
     *
     * @return bool True if locked_at IS NOT NULL in the DB.
     */
    public function isLocked(string $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT locked_at FROM {$this->table} WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        return $row['locked_at'] !== null;
    }

    /**
     * Reset the failure counter and lock for the given user.
     *
     * Call this after a successful login to clear any prior failures.
     */
    public function reset(string $userId): void
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = "INSERT OR REPLACE INTO {$this->table} (user_id, failures, locked_at, updated_at)
                    VALUES (?, 0, NULL, datetime('now'))";
        } else {
            $sql = "INSERT INTO {$this->table} (user_id, failures, locked_at, updated_at)
                    VALUES (?, 0, NULL, NOW())
                    ON DUPLICATE KEY UPDATE
                        failures   = 0,
                        locked_at  = NULL,
                        updated_at = NOW()";
        }
        $this->db->prepare($sql)->execute([$userId]);
    }

    /**
     * Return the current failure count (0 if no record exists).
     */
    public function failureCount(string $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT failures FROM {$this->table} WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (int) $row['failures'] : 0;
    }
}
