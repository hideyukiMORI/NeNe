<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * ConfigStore — global application key-value configuration store.
 *
 * Stores typed configuration values in the database. Unlike UserPreference
 * (which is per-user), ConfigStore holds application-wide settings shared
 * across all users and sessions.
 *
 * Keys support a dot-namespace convention (e.g. `mail.from`, `mail.host`)
 * for logical grouping; the store treats them as plain strings.
 *
 * ## Usage
 *
 * ```php
 * $cfg = new ConfigStore($pdo);
 *
 * $cfg->set('mail.from', 'no-reply@example.com');
 * $cfg->set('feature.limit', '100');
 *
 * $cfg->get('mail.from');             // 'no-reply@example.com'
 * $cfg->getInt('feature.limit');      // 100
 * $cfg->getBool('feature.enabled');   // false (missing → false)
 *
 * $cfg->all();                        // ['mail.from' => '...', ...]
 * $cfg->namespace('mail');            // all keys starting with 'mail.'
 * $cfg->delete('mail.from');
 * $cfg->deleteNamespace('mail');      // delete all 'mail.*' keys
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE config_store (
 *     config_key   VARCHAR(255) NOT NULL PRIMARY KEY,
 *     config_value TEXT         NOT NULL DEFAULT '',
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ConfigStore
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set (upsert) a configuration value.
     *
     * @throws \InvalidArgumentException if the key is empty.
     */
    public function set(string $key, string $value): void
    {
        $key = $this->validateKey($key);
        $db  = $this->db();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO config_store (config_key, config_value)
                 VALUES (:k, :v)
                 ON CONFLICT (config_key)
                 DO UPDATE SET config_value = excluded.config_value,
                               updated_at   = CURRENT_TIMESTAMP'
            )->execute([':k' => $key, ':v' => $value]);
        } else {
            $db->prepare(
                'INSERT INTO config_store (config_key, config_value)
                 VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE config_value = VALUES(config_value),
                                         updated_at   = CURRENT_TIMESTAMP'
            )->execute([':k' => $key, ':v' => $value]);
        }
    }

    /**
     * Get a configuration value as a string.
     *
     * @param string $default Returned when the key does not exist.
     */
    public function get(string $key, string $default = ''): string
    {
        $key  = $this->validateKey($key);
        $stmt = $this->db()->prepare(
            'SELECT config_value FROM config_store WHERE config_key = :k LIMIT 1'
        );
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $default;
    }

    /**
     * Get a configuration value as an integer.
     */
    public function getInt(string $key, int $default = 0): int
    {
        $raw = $this->get($key);
        return $raw !== '' ? (int)$raw : $default;
    }

    /**
     * Get a configuration value as a boolean.
     *
     * Truthy strings: '1', 'true', 'yes', 'on' (case-insensitive).
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $raw = $this->get($key);
        if ($raw === '') {
            return $default;
        }
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Check whether a key exists.
     */
    public function has(string $key): bool
    {
        $key  = $this->validateKey($key);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM config_store WHERE config_key = :k'
        );
        $stmt->execute([':k' => $key]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Delete a key.
     *
     * @return bool True if the key existed and was deleted.
     */
    public function delete(string $key): bool
    {
        $key  = $this->validateKey($key);
        $stmt = $this->db()->prepare(
            'DELETE FROM config_store WHERE config_key = :k'
        );
        $stmt->execute([':k' => $key]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return all configuration entries as key => value pairs.
     *
     * @return array<string,string>
     */
    public function all(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT config_key, config_value FROM config_store ORDER BY config_key ASC'
        );
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(string)$row['config_key']] = (string)$row['config_value'];
        }
        return $result;
    }

    /**
     * Return all entries whose key starts with the given prefix followed by '.'.
     *
     * For example, `namespace('mail')` returns all `mail.*` entries.
     *
     * @return array<string,string>
     */
    public function namespace(string $prefix): array
    {
        $prefix = trim($prefix);
        $like   = $this->db()->quote($prefix . '.%');
        $stmt   = $this->db()->prepare(
            "SELECT config_key, config_value FROM config_store WHERE config_key LIKE {$like} ORDER BY config_key ASC"
        );
        $stmt->execute();
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $result[(string)$row['config_key']] = (string)$row['config_value'];
        }
        return $result;
    }

    /**
     * Delete all keys in a namespace (prefix + '.').
     *
     * @return int Number of rows deleted.
     */
    public function deleteNamespace(string $prefix): int
    {
        $prefix = trim($prefix);
        $like   = $this->db()->quote($prefix . '.%');
        $stmt   = $this->db()->prepare(
            "DELETE FROM config_store WHERE config_key LIKE {$like}"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Count stored keys.
     */
    public function count(): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM config_store');
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('config_key must not be empty.');
        }
        return $key;
    }
}
