<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Password expiry policy enforcement.
 *
 * Tracks when each user's password expires and whether a forced password
 * change is required (e.g. after an admin reset or a security incident).
 * Call set() after every successful password change to reset the clock.
 *
 * Distinct from PasswordHistory (which prevents reuse of old passwords).
 *
 * ## Usage
 *
 * ```php
 * $pe = new PasswordExpiry($pdo);
 *
 * // After a password change, reset the expiry clock (90-day policy)
 * $pe->set('user-1', 90);
 *
 * // Check on login
 * if ($pe->mustChange('user-1')) {
 *     // Force user to choose a new password
 * }
 *
 * // Security incident: force all impacted users to change password
 * $pe->forceChange('user-1');
 *
 * // Find accounts expiring within 7 days (for proactive notification)
 * $upcoming = $pe->expiringSoon(7);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE password_expiry (
 *     user_id      VARCHAR(255) PRIMARY KEY,
 *     expires_at   DATETIME     NOT NULL,
 *     changed_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     must_change  TINYINT(1)   NOT NULL DEFAULT 0
 * );
 * ```
 */
final class PasswordExpiry
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    // ── set ───────────────────────────────────────────────────────────────────

    /**
     * Record a password change and set the expiry $expiryDays from now.
     *
     * Clears any must_change flag and upserts the row.
     *
     * @throws \InvalidArgumentException if userId is empty or expiryDays < 1.
     */
    public function set(string $userId, int $expiryDays): void
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty.');
        }

        if ($expiryDays < 1) {
            throw new \InvalidArgumentException('expiryDays must be >= 1.');
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
        $driver    = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $stmt = $this->db()->prepare(
                'INSERT INTO password_expiry (user_id, expires_at, must_change)
                 VALUES (?, ?, 0)
                 ON DUPLICATE KEY UPDATE
                     expires_at  = VALUES(expires_at),
                     changed_at  = CURRENT_TIMESTAMP,
                     must_change = 0'
            );
        } else {
            $stmt = $this->db()->prepare(
                'INSERT INTO password_expiry (user_id, expires_at, must_change)
                 VALUES (?, ?, 0)
                 ON CONFLICT (user_id)
                 DO UPDATE SET
                     expires_at  = excluded.expires_at,
                     changed_at  = CURRENT_TIMESTAMP,
                     must_change = 0'
            );
        }

        $stmt->execute([$userId, $expiresAt]);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    /**
     * Retrieve the expiry row for a user.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $userId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM password_expiry WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── checks ────────────────────────────────────────────────────────────────

    /**
     * Check whether the user's password is past its expiry date.
     *
     * Returns false if no expiry record exists (policy not enforced).
     */
    public function isExpired(string $userId): bool
    {
        $row = $this->get($userId);

        if ($row === null) {
            return false;
        }

        return strtotime((string)$row['expires_at']) < time();
    }

    /**
     * Check whether the user must change their password.
     *
     * Returns true if must_change = 1 OR the password is expired.
     */
    public function mustChange(string $userId): bool
    {
        $row = $this->get($userId);

        if ($row === null) {
            return false;
        }

        return (int)$row['must_change'] === 1 || $this->isExpired($userId);
    }

    // ── forceChange ───────────────────────────────────────────────────────────

    /**
     * Require the user to change their password on next login.
     *
     * Returns false if no expiry record exists for this user.
     */
    public function forceChange(string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE password_expiry SET must_change = 1 WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    /**
     * Remove the expiry record (no policy applied to this user).
     */
    public function clear(string $userId): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM password_expiry WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->rowCount() > 0;
    }

    // ── expiringSoon ──────────────────────────────────────────────────────────

    /**
     * Return rows for users whose password expires within $withinDays days.
     *
     * Excludes already-expired rows (those should be handled by isExpired()).
     *
     * @return array<int,array<string,mixed>>
     */
    public function expiringSoon(int $withinDays = 7, ?string $now = null): array
    {
        $currentTime = $now ?? date('Y-m-d H:i:s');
        $cutoff      = date('Y-m-d H:i:s', strtotime("+{$withinDays} days", strtotime($currentTime)));
        $stmt        = $this->db()->prepare(
            'SELECT * FROM password_expiry
             WHERE expires_at > ? AND expires_at <= ?
             ORDER BY expires_at ASC'
        );
        $stmt->execute([$currentTime, $cutoff]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
