<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Maintenance mode — global on/off flag with allowlist for bypass users.
 *
 * Maintenance state is stored in a key-value table (one row per setting).
 * An allowlisted user (e.g. an admin) bypasses the maintenance gate and
 * can access the site even while maintenance mode is active.
 *
 * ## Usage
 *
 * ```php
 * $mm = new MaintenanceMode($pdo);
 *
 * // Enable
 * $mm->enable('Deploying v2.0', scheduledEnd: '2026-06-01 03:00:00');
 *
 * // Check
 * if ($mm->isActive() && !$mm->isAllowed('user-1')) {
 *     http_response_code(503);
 *     exit('Under maintenance');
 * }
 *
 * // Allowlist an admin
 * $mm->allow('admin-1');
 *
 * // Disable
 * $mm->disable();
 *
 * // Status
 * $status = $mm->status(); // ['active' => true, 'message' => '...', 'scheduled_end' => '...']
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE maintenance_mode (
 *     key        VARCHAR(50)  NOT NULL PRIMARY KEY,
 *     value      TEXT         NOT NULL DEFAULT '',
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE maintenance_allowlist (
 *     user_id    VARCHAR(255) NOT NULL PRIMARY KEY,
 *     added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class MaintenanceMode
{
    private const KEY_ACTIVE        = 'active';
    private const KEY_MESSAGE       = 'message';
    private const KEY_SCHEDULED_END = 'scheduled_end';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Enable maintenance mode.
     *
     * @param string $message      Human-readable reason shown to users.
     * @param string $scheduledEnd Optional ISO-8601 datetime when maintenance ends (informational).
     */
    public function enable(string $message = '', string $scheduledEnd = ''): void
    {
        $this->setSetting(self::KEY_ACTIVE, '1');
        $this->setSetting(self::KEY_MESSAGE, $message);
        $this->setSetting(self::KEY_SCHEDULED_END, $scheduledEnd);
    }

    /**
     * Disable maintenance mode.
     */
    public function disable(): void
    {
        $this->setSetting(self::KEY_ACTIVE, '0');
    }

    /**
     * Check whether maintenance mode is currently active.
     */
    public function isActive(): bool
    {
        return $this->getSetting(self::KEY_ACTIVE) === '1';
    }

    /**
     * Return a status snapshot.
     *
     * @return array{active: bool, message: string, scheduled_end: string}
     */
    public function status(): array
    {
        return [
            'active'        => $this->isActive(),
            'message'       => $this->getSetting(self::KEY_MESSAGE) ?? '',
            'scheduled_end' => $this->getSetting(self::KEY_SCHEDULED_END) ?? '',
        ];
    }

    /**
     * Add a user to the bypass allowlist.
     *
     * Idempotent — adding the same user twice is a no-op.
     */
    public function allow(string $userId): void
    {
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO maintenance_allowlist (user_id) VALUES (:user)'
            : 'INSERT IGNORE INTO maintenance_allowlist (user_id) VALUES (:user)';

        $db->prepare($sql)->execute([':user' => $userId]);
    }

    /**
     * Remove a user from the bypass allowlist.
     *
     * @return bool True if the user was on the allowlist.
     */
    public function disallow(string $userId): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM maintenance_allowlist WHERE user_id = :user');
        $stmt->execute([':user' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if a user is on the bypass allowlist.
     *
     * Returns true also when maintenance mode is not active (user is always allowed).
     */
    public function isAllowed(string $userId): bool
    {
        if (!$this->isActive()) {
            return true;
        }
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM maintenance_allowlist WHERE user_id = :user LIMIT 1'
        );
        $stmt->execute([':user' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Return all users on the bypass allowlist.
     *
     * @return list<string>
     */
    public function allowlist(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT user_id FROM maintenance_allowlist ORDER BY added_at ASC'
        );
        $stmt->execute();
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id');
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function setSetting(string $key, string $value): void
    {
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'INSERT OR REPLACE INTO maintenance_mode (key, value, updated_at)
                 VALUES (:key, :value, CURRENT_TIMESTAMP)'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO maintenance_mode (key, value, updated_at)
                 VALUES (:key, :value, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    private function getSetting(string $key): ?string
    {
        $stmt = $this->db()->prepare(
            'SELECT value FROM maintenance_mode WHERE key = :key LIMIT 1'
        );
        $stmt->execute([':key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }
}
