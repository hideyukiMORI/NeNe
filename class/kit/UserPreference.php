<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * UserPreference — key-value preference store per user with typed defaults.
 *
 * Stores arbitrary string key-value pairs per user. Supports scalar and JSON
 * values. Returns a caller-supplied default when no preference is stored.
 *
 * ## Usage
 *
 * ```php
 * $up = new UserPreference($pdo);
 *
 * // Store a preference
 * $up->set('user-1', 'theme', 'dark');
 * $up->set('user-1', 'locale', 'ja');
 *
 * // Retrieve (with default)
 * $up->get('user-1', 'theme', 'light');   // 'dark'
 * $up->get('user-1', 'font_size', '14');  // '14' (default)
 *
 * // All preferences for a user
 * $up->all('user-1');
 *
 * // Delete a key or all preferences
 * $up->delete('user-1', 'theme');
 * $up->deleteAll('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_preferences (
 *     user_id    VARCHAR(255) NOT NULL,
 *     pref_key   VARCHAR(100) NOT NULL,
 *     pref_value TEXT         NOT NULL DEFAULT '',
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (user_id, pref_key)
 * );
 * ```
 */
final class UserPreference
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set a preference value for a user (upsert).
     *
     * @throws \InvalidArgumentException if user_id or pref_key is empty.
     */
    public function set(string $userId, string $key, string $value): void
    {
        [$userId, $key] = $this->normalise($userId, $key);
        $db             = $this->db();
        $driver         = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO user_preferences (user_id, pref_key, pref_value)
                 VALUES (:uid, :key, :val)
                 ON CONFLICT (user_id, pref_key)
                 DO UPDATE SET pref_value = excluded.pref_value, updated_at = CURRENT_TIMESTAMP'
            )->execute([':uid' => $userId, ':key' => $key, ':val' => $value]);
        } else {
            $db->prepare(
                'INSERT INTO user_preferences (user_id, pref_key, pref_value)
                 VALUES (:uid, :key, :val)
                 ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = CURRENT_TIMESTAMP'
            )->execute([':uid' => $userId, ':key' => $key, ':val' => $value]);
        }
    }

    /**
     * Get a preference value, falling back to $default if not set.
     *
     * @throws \InvalidArgumentException if user_id or pref_key is empty.
     */
    public function get(string $userId, string $key, string $default = ''): string
    {
        [$userId, $key] = $this->normalise($userId, $key);
        $stmt           = $this->db()->prepare(
            'SELECT pref_value FROM user_preferences WHERE user_id = :uid AND pref_key = :key LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $default;
    }

    /**
     * Check whether a preference key has been explicitly set for a user.
     */
    public function has(string $userId, string $key): bool
    {
        [$userId, $key] = $this->normalise($userId, $key);
        $stmt           = $this->db()->prepare(
            'SELECT COUNT(*) FROM user_preferences WHERE user_id = :uid AND pref_key = :key'
        );
        $stmt->execute([':uid' => $userId, ':key' => $key]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get all preferences for a user as key-value map.
     *
     * @return array<string,string>
     */
    public function all(string $userId): array
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare(
            'SELECT pref_key, pref_value FROM user_preferences WHERE user_id = :uid ORDER BY pref_key ASC'
        );
        $stmt->execute([':uid' => $userId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string)$row['pref_key']] = (string)$row['pref_value'];
        }
        return $result;
    }

    /**
     * Delete a single preference key.
     *
     * @return bool True if a row was deleted.
     */
    public function delete(string $userId, string $key): bool
    {
        [$userId, $key] = $this->normalise($userId, $key);
        $stmt           = $this->db()->prepare(
            'DELETE FROM user_preferences WHERE user_id = :uid AND pref_key = :key'
        );
        $stmt->execute([':uid' => $userId, ':key' => $key]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all preferences for a user.
     *
     * @return int Number of rows deleted.
     */
    public function deleteAll(string $userId): int
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare('DELETE FROM user_preferences WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * Count stored preferences for a user.
     */
    public function count(string $userId): int
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare('SELECT COUNT(*) FROM user_preferences WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $userId, string $key): array
    {
        $userId = trim($userId);
        $key    = trim($key);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($key === '') {
            throw new \InvalidArgumentException('pref_key must not be empty.');
        }
        return [$userId, $key];
    }
}
