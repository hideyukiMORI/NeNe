<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * User preference key-value store with default-value fallback and type casting.
 *
 * Preferences are stored as strings; type casting methods convert on retrieval.
 * Setting a key to null removes the preference, causing the default to be returned.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE user_preferences (
 *     user_id    VARCHAR(255) NOT NULL,
 *     pref_key   VARCHAR(255) NOT NULL,
 *     pref_value TEXT         NOT NULL,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (user_id, pref_key)
 * );
 * ```
 *
 * ## Usage
 *
 * ```php
 * $prefs = new UserPreference($pdo);
 *
 * // Set
 * $prefs->set('user:1', 'theme', 'dark');
 * $prefs->set('user:1', 'items_per_page', '20');
 *
 * // Get with default fallback
 * $prefs->get('user:1', 'theme', 'light');          // 'dark'
 * $prefs->get('user:1', 'language', 'ja');          // 'ja' (default)
 *
 * // Type casting
 * $prefs->getInt('user:1', 'items_per_page', 10);   // 20 (int)
 * $prefs->getBool('user:1', 'notifications', true); // true (default)
 *
 * // Delete (revert to default)
 * $prefs->delete('user:1', 'theme');
 *
 * // All preferences for a user
 * $prefs->all('user:1');
 * ```
 */
final class UserPreference
{
    private const TABLE = 'user_preferences';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Get a preference value.
     *
     * Returns the stored string value, or $default if the key is not set.
     *
     * @param string      $userId  Application-level user identifier.
     * @param string      $key     Preference key.
     * @param string|null $default Default value when key is not set.
     */
    public function get(string $userId, string $key, ?string $default = null): ?string
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT pref_value FROM ' . $this->table .
            ' WHERE user_id = :u AND pref_key = :k LIMIT 1'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':k', $key, PDO::PARAM_STR);
        $stmt->execute();

        $value = $stmt->fetchColumn();
        return $value !== false ? (string)$value : $default;
    }

    /**
     * Get a preference as an integer.
     *
     * @param string $userId  Application-level user identifier.
     * @param string $key     Preference key.
     * @param int    $default Default value when key is not set.
     */
    public function getInt(string $userId, string $key, int $default = 0): int
    {
        $value = $this->get($userId, $key);
        return $value !== null ? (int)$value : $default;
    }

    /**
     * Get a preference as a boolean.
     *
     * Stored as '1'/'0', 'true'/'false', 'yes'/'no' (case-insensitive).
     *
     * @param string $userId  Application-level user identifier.
     * @param string $key     Preference key.
     * @param bool   $default Default value when key is not set.
     */
    public function getBool(string $userId, string $key, bool $default = false): bool
    {
        $value = $this->get($userId, $key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    /**
     * Set a preference value. Creates or overwrites the existing value.
     *
     * @param string $userId Application-level user identifier.
     * @param string $key    Preference key.
     * @param string $value  Value to store.
     */
    public function set(string $userId, string $key, string $value): void
    {
        $db     = $this->db ?? PdoConnection::getInstance();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = 'INSERT OR REPLACE INTO ' . $this->table .
                   ' (user_id, pref_key, pref_value, updated_at)' .
                   ' VALUES (:u, :k, :v, :t)';
        } else {
            $sql = 'INSERT INTO ' . $this->table .
                   ' (user_id, pref_key, pref_value, updated_at)' .
                   ' VALUES (:u, :k, :v, :t)' .
                   ' ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = VALUES(updated_at)';
        }

        $updatedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt      = $db->prepare($sql);
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':k', $key, PDO::PARAM_STR);
        $stmt->bindValue(':v', $value, PDO::PARAM_STR);
        $stmt->bindValue(':t', $updatedAt, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Delete a preference. After deletion, get() returns the default.
     *
     * Returns true if a row was deleted, false if the key did not exist.
     *
     * @param string $userId Application-level user identifier.
     * @param string $key    Preference key to remove.
     */
    public function delete(string $userId, string $key): bool
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'DELETE FROM ' . $this->table .
            ' WHERE user_id = :u AND pref_key = :k'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':k', $key, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Get all preferences for a user as a key→value map.
     *
     * @param  string $userId Application-level user identifier.
     * @return array<string, string>
     */
    public function all(string $userId): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT pref_key, pref_value FROM ' . $this->table .
            ' WHERE user_id = :u ORDER BY pref_key ASC'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['pref_key']] = (string)$row['pref_value'];
        }
        return $result;
    }
}
