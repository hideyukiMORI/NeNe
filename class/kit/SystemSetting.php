<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * SystemSetting — typed global application settings store.
 *
 * Stores admin-controlled application-wide key-value settings with optional
 * type casting (string, int, bool, json) and category grouping.
 * Distinct from ConfigStore (entity-scoped) and UserPreference (user-scoped).
 *
 * ## Usage
 *
 * ```php
 * $ss = new SystemSetting($pdo);
 *
 * $ss->set('site.name', 'My App');
 * $ss->set('max_upload_mb', '50', 'int', 'upload');
 * $ss->set('maintenance', '0', 'bool');
 * $ss->set('allowed_mimes', '["image/png","image/jpeg"]', 'json', 'upload');
 *
 * echo $ss->getString('site.name');   // 'My App'
 * echo $ss->getInt('max_upload_mb'); // 50
 * echo $ss->getBool('maintenance') ? 'down' : 'up';
 * print_r($ss->getJson('allowed_mimes'));
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE system_settings (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     key        VARCHAR(100) NOT NULL UNIQUE,
 *     value      TEXT         NULL,
 *     type       VARCHAR(10)  NOT NULL DEFAULT 'string',
 *     category   VARCHAR(100) NOT NULL DEFAULT 'general',
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class SystemSetting
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT    = 'int';
    public const TYPE_BOOL   = 'bool';
    public const TYPE_JSON   = 'json';

    private const ALLOWED_TYPES = [self::TYPE_STRING, self::TYPE_INT, self::TYPE_BOOL, self::TYPE_JSON];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create or update a setting.
     *
     * @throws \InvalidArgumentException on empty key or unknown type.
     */
    public function set(string $key, ?string $value, string $type = self::TYPE_STRING, string $category = 'general'): void
    {
        $key = trim($key);
        if ($key === '') {
            throw new \InvalidArgumentException('key must not be empty.');
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown type '{$type}'. Must be one of: " . implode(', ', self::ALLOWED_TYPES));
        }
        $category = trim($category) === '' ? 'general' : trim($category);
        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO system_settings (key, value, type, category, updated_at)
                    VALUES (:key, :val, :type, :cat, :now)
                    ON CONFLICT (key) DO UPDATE SET value = excluded.value,
                        type = excluded.type, category = excluded.category, updated_at = excluded.updated_at';
        } else {
            $sql = 'INSERT INTO system_settings (key, value, type, category, updated_at)
                    VALUES (:key, :val, :type, :cat, :now)
                    ON DUPLICATE KEY UPDATE value = VALUES(value),
                        type = VALUES(type), category = VALUES(category), updated_at = VALUES(updated_at)';
        }
        $stmt = $this->db()->prepare($sql);
        $stmt->execute([':key' => $key, ':val' => $value, ':type' => $type, ':cat' => $category, ':now' => $now]);
    }

    /**
     * Get the raw stored value, cast by type; returns default when missing.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->findRow($key);
        if ($row === null) {
            return $default;
        }
        return $this->cast((string)$row['value'], (string)$row['type']);
    }

    /**
     * Get as string.
     */
    public function getString(string $key, string $default = ''): string
    {
        $row = $this->findRow($key);
        if ($row === null || $row['value'] === null) {
            return $default;
        }
        return (string)$row['value'];
    }

    /**
     * Get as int.
     */
    public function getInt(string $key, int $default = 0): int
    {
        $row = $this->findRow($key);
        if ($row === null || $row['value'] === null) {
            return $default;
        }
        return (int)$row['value'];
    }

    /**
     * Get as bool. Truthy: "1", "true", "yes", "on" (case-insensitive).
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $row = $this->findRow($key);
        if ($row === null || $row['value'] === null) {
            return $default;
        }
        return $this->parseBool((string)$row['value']);
    }

    /**
     * Get as decoded JSON array (empty array on missing or null).
     *
     * @return array<mixed>
     */
    public function getJson(string $key, array $default = []): array
    {
        $row = $this->findRow($key);
        if ($row === null || $row['value'] === null) {
            return $default;
        }
        $decoded = json_decode((string)$row['value'], true);
        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * Delete a setting. Returns true if found and removed.
     */
    public function delete(string $key): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM system_settings WHERE key = :key');
        $stmt->execute([':key' => trim($key)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return all settings for a given category, ordered by key.
     *
     * @return list<array<string,mixed>>
     */
    public function allForCategory(string $category): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, key, value, type, category, updated_at
             FROM system_settings WHERE category = :cat ORDER BY key ASC'
        );
        $stmt->execute([':cat' => $category]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return all settings ordered by category, then key.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $stmt = $this->db()->query(
            'SELECT id, key, value, type, category, updated_at
             FROM system_settings ORDER BY category ASC, key ASC'
        );
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRow(string $key): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT value, type FROM system_settings WHERE key = :key'
        );
        $stmt->execute([':key' => trim($key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            self::TYPE_INT  => (int)$value,
            self::TYPE_BOOL => $this->parseBool($value),
            self::TYPE_JSON => is_array($decoded = json_decode($value, true)) ? $decoded : [],
            default         => $value,
        };
    }

    private function parseBool(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
