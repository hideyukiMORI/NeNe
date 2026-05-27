<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Notification preference manager — per-user channel × type opt-in/opt-out.
 *
 * Each preference is keyed by `(user_id, channel, type)`:
 *  - `channel` — delivery channel (e.g. `'email'`, `'push'`, `'sms'`)
 *  - `type`    — notification category (e.g. `'marketing'`, `'order_update'`)
 *
 * Preferences not yet stored default to `true` (opted-in).
 * Call `setEnabled(…, false)` to opt out; `reset()` to revert to default.
 *
 * ## Usage
 *
 * ```php
 * $prefs = new NotificationPreference($pdo);
 *
 * // Opt out of marketing emails
 * $prefs->setEnabled('user-1', 'email', 'marketing', false);
 *
 * // Check
 * $prefs->isEnabled('user-1', 'email', 'marketing'); // false
 * $prefs->isEnabled('user-1', 'email', 'order');     // true (default)
 *
 * // List all stored prefs for a user
 * $prefs->all('user-1');
 *
 * // Reset a specific preference to default
 * $prefs->reset('user-1', 'email', 'marketing');
 *
 * // Reset all preferences for a user
 * $prefs->resetAll('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE notification_preferences (
 *     user_id    VARCHAR(255) NOT NULL,
 *     channel    VARCHAR(50)  NOT NULL,
 *     type       VARCHAR(100) NOT NULL,
 *     enabled    TINYINT(1)   NOT NULL DEFAULT 1,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (user_id, channel, type)
 * );
 * ```
 */
final class NotificationPreference
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set whether a user has opted in or out of a notification channel+type.
     *
     * Upserts the row: creates if absent, updates if present.
     *
     * @throws \InvalidArgumentException if channel or type is empty.
     */
    public function setEnabled(string $userId, string $channel, string $type, bool $enabled): void
    {
        [$channel, $type] = $this->normalise($channel, $type);
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'INSERT OR REPLACE INTO notification_preferences (user_id, channel, type, enabled, updated_at)
                 VALUES (:user, :channel, :type, :enabled, CURRENT_TIMESTAMP)'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO notification_preferences (user_id, channel, type, enabled, updated_at)
                 VALUES (:user, :channel, :type, :enabled, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([
            ':user'    => $userId,
            ':channel' => $channel,
            ':type'    => $type,
            ':enabled' => $enabled ? 1 : 0,
        ]);
    }

    /**
     * Check whether a user has opted in to a notification channel+type.
     *
     * Defaults to `true` if no preference record exists.
     */
    public function isEnabled(string $userId, string $channel, string $type): bool
    {
        [$channel, $type] = $this->normalise($channel, $type);
        $stmt = $this->db()->prepare(
            'SELECT enabled FROM notification_preferences
             WHERE user_id = :user AND channel = :channel AND type = :type
             LIMIT 1'
        );
        $stmt->execute([':user' => $userId, ':channel' => $channel, ':type' => $type]);
        $row = $stmt->fetchColumn();
        return $row === false ? true : (bool)(int)$row; // default opt-in
    }

    /**
     * Return all stored preferences for a user.
     *
     * @return list<array{channel: string, type: string, enabled: bool}>
     */
    public function all(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT channel, type, enabled
             FROM notification_preferences
             WHERE user_id = :user
             ORDER BY channel ASC, type ASC'
        );
        $stmt->execute([':user' => $userId]);
        return array_map(
            static fn (array $r): array => [
                'channel' => $r['channel'],
                'type'    => $r['type'],
                'enabled' => (bool)(int)$r['enabled'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Delete a specific preference row (resets to default opt-in behaviour).
     *
     * @return bool True if a row was deleted.
     */
    public function reset(string $userId, string $channel, string $type): bool
    {
        [$channel, $type] = $this->normalise($channel, $type);
        $stmt = $this->db()->prepare(
            'DELETE FROM notification_preferences WHERE user_id = :user AND channel = :channel AND type = :type'
        );
        $stmt->execute([':user' => $userId, ':channel' => $channel, ':type' => $type]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all preference rows for a user (reverts everything to default opt-in).
     *
     * @return int Number of rows deleted.
     */
    public function resetAll(string $userId): int
    {
        $stmt = $this->db()->prepare('DELETE FROM notification_preferences WHERE user_id = :user');
        $stmt->execute([':user' => $userId]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $channel, string $type): array
    {
        $channel = trim($channel);
        $type    = trim($type);
        if ($channel === '') {
            throw new \InvalidArgumentException('Channel must not be empty.');
        }
        if ($type === '') {
            throw new \InvalidArgumentException('Type must not be empty.');
        }
        return [$channel, $type];
    }
}
