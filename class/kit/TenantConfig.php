<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * TenantConfig — per-tenant key-value configuration store.
 *
 * Manages configuration overrides on a per-tenant basis for multi-tenant
 * or per-organization SaaS applications. Supports typed values
 * (string/int/bool/json) with optional default fallbacks.
 *
 * Distinct from:
 * - `ConfigStore`   (global application configuration)
 * - `SystemSetting` (typed global admin settings)
 * - `UserPreference` (per-user preferences)
 *
 * ## Usage
 *
 * ```php
 * $tc = new TenantConfig($pdo);
 *
 * $tc->set('tenant-abc', 'max_seats', '50');
 * $tc->set('tenant-abc', 'feature_analytics', 'true');
 * $tc->get('tenant-abc', 'max_seats');           // '50'
 * $tc->getInt('tenant-abc', 'max_seats', 10);    // 50
 * $tc->getBool('tenant-abc', 'feature_analytics'); // true
 * $tc->all('tenant-abc');                         // all k/v pairs
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE tenant_configs (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     tenant_id  VARCHAR(255) NOT NULL,
 *     config_key VARCHAR(100) NOT NULL,
 *     value      TEXT         NULL,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (tenant_id, config_key)
 * );
 * ```
 */
final class TenantConfig
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Set a configuration value for a tenant (upsert).
     *
     * @throws \InvalidArgumentException on empty tenantId or key.
     */
    public function set(string $tenantId, string $key, ?string $value): void
    {
        $tenantId = trim($tenantId);
        $key      = trim($key);
        if ($tenantId === '') {
            throw new \InvalidArgumentException('tenant_id must not be empty.');
        }
        if ($key === '') {
            throw new \InvalidArgumentException('config_key must not be empty.');
        }

        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO tenant_configs (tenant_id, config_key, value, updated_at)
                    VALUES (:tid, :key, :val, :now)
                    ON CONFLICT (tenant_id, config_key) DO UPDATE SET
                        value      = excluded.value,
                        updated_at = excluded.updated_at';
        } else {
            $sql = 'INSERT INTO tenant_configs (tenant_id, config_key, value, updated_at)
                    VALUES (:tid, :key, :val, :now)
                    ON DUPLICATE KEY UPDATE
                        value      = VALUES(value),
                        updated_at = VALUES(updated_at)';
        }
        $this->db()->prepare($sql)->execute([
            ':tid' => $tenantId,
            ':key' => $key,
            ':val' => $value,
            ':now' => $now,
        ]);
    }

    /**
     * Get a raw string value for a tenant/key.
     * Returns $default if not set.
     */
    public function get(string $tenantId, string $key, ?string $default = null): ?string
    {
        $stmt = $this->db()->prepare(
            'SELECT value FROM tenant_configs WHERE tenant_id = :tid AND config_key = :key'
        );
        $stmt->execute([':tid' => trim($tenantId), ':key' => trim($key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row['value'] : $default;
    }

    /**
     * Get value as integer.
     */
    public function getInt(string $tenantId, string $key, int $default = 0): int
    {
        $val = $this->get($tenantId, $key);
        return $val !== null ? (int)$val : $default;
    }

    /**
     * Get value as boolean ('true'/'1'/'yes' → true; others → false).
     */
    public function getBool(string $tenantId, string $key, bool $default = false): bool
    {
        $val = $this->get($tenantId, $key);
        if ($val === null) {
            return $default;
        }
        return in_array(strtolower($val), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Get value decoded from JSON.
     *
     * @return mixed Decoded JSON value, or $default if not set or invalid JSON.
     */
    public function getJson(string $tenantId, string $key, mixed $default = null): mixed
    {
        $val = $this->get($tenantId, $key);
        if ($val === null) {
            return $default;
        }
        $decoded = json_decode($val, true);
        return ($decoded !== null) ? $decoded : $default;
    }

    /**
     * Check whether a key is configured for a tenant.
     */
    public function has(string $tenantId, string $key): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM tenant_configs WHERE tenant_id = :tid AND config_key = :key'
        );
        $stmt->execute([':tid' => trim($tenantId), ':key' => trim($key)]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Remove a configuration key for a tenant.
     *
     * @return bool True if the key existed and was deleted.
     */
    public function delete(string $tenantId, string $key): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM tenant_configs WHERE tenant_id = :tid AND config_key = :key'
        );
        $stmt->execute([':tid' => trim($tenantId), ':key' => trim($key)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * All configuration entries for a tenant, as key → value map.
     *
     * @return array<string,string|null>
     */
    public function all(string $tenantId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT config_key, value FROM tenant_configs WHERE tenant_id = :tid
             ORDER BY config_key ASC'
        );
        $stmt->execute([':tid' => trim($tenantId)]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[$row['config_key']] = $row['value'];
        }
        return $result;
    }

    /**
     * Remove all configuration entries for a tenant.
     *
     * @return int Number of rows deleted.
     */
    public function purge(string $tenantId): int
    {
        $stmt = $this->db()->prepare('DELETE FROM tenant_configs WHERE tenant_id = :tid');
        $stmt->execute([':tid' => trim($tenantId)]);
        return $stmt->rowCount();
    }

    /**
     * Copy all config from one tenant to another (replaces existing keys).
     *
     * @return int Number of keys copied.
     */
    public function copyTo(string $sourceTenantId, string $targetTenantId): int
    {
        $configs = $this->all($sourceTenantId);
        foreach ($configs as $key => $value) {
            $this->set($targetTenantId, $key, $value);
        }
        return count($configs);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
