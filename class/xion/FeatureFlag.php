<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * FeatureFlag — DB-backed feature flags with global on/off and per-user overrides.
 *
 * Flags are globally enabled or disabled, with per-user overrides that take precedence.
 * Useful for gradual rollouts, A/B testing, and beta access control.
 *
 * ## Usage
 *
 * ```php
 * $ff = new FeatureFlag($pdo);
 *
 * // Enable/disable globally
 * $ff->enable('dark-mode');
 * $ff->disable('beta-editor');
 *
 * // Per-user override
 * $ff->enableForUser('beta-editor', 'user-1');
 * $ff->disableForUser('dark-mode', 'user-2');
 *
 * // Check
 * $ff->isEnabled('dark-mode');               // global check
 * $ff->isEnabledForUser('dark-mode', 'user-1'); // user-aware check
 *
 * // List all flags
 * $ff->all();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE feature_flags (
 *     flag_name  VARCHAR(255) NOT NULL PRIMARY KEY,
 *     enabled    TINYINT(1)   NOT NULL DEFAULT 0,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE feature_flag_overrides (
 *     flag_name VARCHAR(255) NOT NULL,
 *     user_id   VARCHAR(255) NOT NULL,
 *     enabled   TINYINT(1)   NOT NULL DEFAULT 1,
 *     PRIMARY KEY (flag_name, user_id)
 * );
 * ```
 */
final class FeatureFlag
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Enable a feature flag globally.
     *
     * Creates the flag if it doesn't exist.
     *
     * @throws \InvalidArgumentException if flag_name is empty.
     */
    public function enable(string $flagName): void
    {
        $this->setGlobal($flagName, true);
    }

    /**
     * Disable a feature flag globally.
     *
     * Creates the flag if it doesn't exist.
     *
     * @throws \InvalidArgumentException if flag_name is empty.
     */
    public function disable(string $flagName): void
    {
        $this->setGlobal($flagName, false);
    }

    /**
     * Check whether a flag is globally enabled (ignores user overrides).
     *
     * Returns false if the flag has never been created.
     */
    public function isEnabled(string $flagName): bool
    {
        $flagName = $this->validateFlag($flagName);
        $stmt     = $this->db()->prepare(
            'SELECT enabled FROM feature_flags WHERE flag_name = :name LIMIT 1'
        );
        $stmt->execute([':name' => $flagName]);
        $val = $stmt->fetchColumn();
        return $val !== false && (int)$val === 1;
    }

    /**
     * Check whether a flag is enabled for a specific user.
     *
     * Resolution order:
     * 1. Per-user override (if set) → use it
     * 2. Global flag value
     *
     * @throws \InvalidArgumentException if flag_name or user_id is empty.
     */
    public function isEnabledForUser(string $flagName, string $userId): bool
    {
        $flagName = $this->validateFlag($flagName);
        $userId   = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'SELECT enabled FROM feature_flag_overrides
             WHERE flag_name = :name AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':name' => $flagName, ':uid' => $userId]);
        $override = $stmt->fetchColumn();

        if ($override !== false) {
            return (int)$override === 1;
        }

        return $this->isEnabled($flagName);
    }

    /**
     * Set a per-user override to enabled.
     */
    public function enableForUser(string $flagName, string $userId): void
    {
        $this->setOverride($flagName, $userId, true);
    }

    /**
     * Set a per-user override to disabled.
     */
    public function disableForUser(string $flagName, string $userId): void
    {
        $this->setOverride($flagName, $userId, false);
    }

    /**
     * Remove the per-user override (fall back to global setting).
     *
     * @return bool True if an override existed and was removed.
     */
    public function removeOverride(string $flagName, string $userId): bool
    {
        $flagName = $this->validateFlag($flagName);
        $userId   = trim($userId);
        $stmt     = $this->db()->prepare(
            'DELETE FROM feature_flag_overrides WHERE flag_name = :name AND user_id = :uid'
        );
        $stmt->execute([':name' => $flagName, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all feature flags with their global status.
     *
     * @return list<array{flag_name: string, enabled: bool}>
     */
    public function all(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT flag_name, enabled FROM feature_flags ORDER BY flag_name ASC'
        );
        $stmt->execute();
        return array_map(
            static fn (array $r) => ['flag_name' => (string)$r['flag_name'], 'enabled' => (int)$r['enabled'] === 1],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Delete a flag and all its overrides.
     *
     * @return bool True if the flag existed.
     */
    public function delete(string $flagName): bool
    {
        $flagName = $this->validateFlag($flagName);
        $db       = $this->db();
        $db->prepare('DELETE FROM feature_flag_overrides WHERE flag_name = :name')->execute([':name' => $flagName]);
        $stmt = $db->prepare('DELETE FROM feature_flags WHERE flag_name = :name');
        $stmt->execute([':name' => $flagName]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Count how many users have an explicit override for a flag.
     */
    public function overrideCount(string $flagName): int
    {
        $flagName = $this->validateFlag($flagName);
        $stmt     = $this->db()->prepare(
            'SELECT COUNT(*) FROM feature_flag_overrides WHERE flag_name = :name'
        );
        $stmt->execute([':name' => $flagName]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateFlag(string $flagName): string
    {
        $flagName = trim($flagName);
        if ($flagName === '') {
            throw new \InvalidArgumentException('flag_name must not be empty.');
        }
        return $flagName;
    }

    private function setGlobal(string $flagName, bool $enabled): void
    {
        $flagName = $this->validateFlag($flagName);
        $db       = $this->db();
        $driver   = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO feature_flags (flag_name, enabled)
                 VALUES (:name, :enabled)
                 ON CONFLICT (flag_name)
                 DO UPDATE SET enabled = excluded.enabled, updated_at = CURRENT_TIMESTAMP'
            )->execute([':name' => $flagName, ':enabled' => $enabled ? 1 : 0]);
        } else {
            $db->prepare(
                'INSERT INTO feature_flags (flag_name, enabled)
                 VALUES (:name, :enabled)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP'
            )->execute([':name' => $flagName, ':enabled' => $enabled ? 1 : 0]);
        }
    }

    private function setOverride(string $flagName, string $userId, bool $enabled): void
    {
        $flagName = $this->validateFlag($flagName);
        $userId   = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO feature_flag_overrides (flag_name, user_id, enabled)
                 VALUES (:name, :uid, :enabled)
                 ON CONFLICT (flag_name, user_id)
                 DO UPDATE SET enabled = excluded.enabled'
            )->execute([':name' => $flagName, ':uid' => $userId, ':enabled' => $enabled ? 1 : 0]);
        } else {
            $db->prepare(
                'INSERT INTO feature_flag_overrides (flag_name, user_id, enabled)
                 VALUES (:name, :uid, :enabled)
                 ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)'
            )->execute([':name' => $flagName, ':uid' => $userId, ':enabled' => $enabled ? 1 : 0]);
        }
    }
}
