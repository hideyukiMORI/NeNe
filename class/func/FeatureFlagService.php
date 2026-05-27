<?php

declare(strict_types=1);

namespace Nene\Func;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DB-backed feature flag evaluation.
 *
 * Evaluates feature flags with a three-tier priority model:
 *   1. Per-user override (highest — acts as both early-access grant and kill switch)
 *   2. Global enable/disable
 *   3. Rollout percentage (deterministic bucket by userId + flagName)
 *   4. Default: false
 *
 * ## Schema
 *
 * feature_flags:
 *   - flag_name VARCHAR(128) UNIQUE
 *   - is_enabled TINYINT(1) DEFAULT 0
 *   - rollout_pct TINYINT UNSIGNED DEFAULT 0  (0–100)
 *
 * flag_overrides:
 *   - flag_name VARCHAR(128)
 *   - user_id BIGINT
 *   - is_enabled TINYINT(1)
 *   - UNIQUE KEY (flag_name, user_id)
 *
 * ## Usage
 *
 * ```php
 * $flags = new FeatureFlagService();
 *
 * // Check with user context (overrides applied)
 * if ($flags->isEnabled('new-checkout', $userId)) { ... }
 *
 * // Check without user context (global only)
 * if ($flags->isEnabled('maintenance-mode')) { ... }
 * ```
 */
final class FeatureFlagService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? PdoConnection::getInstance();
    }

    /**
     * Evaluate a feature flag for an optional user.
     *
     * @param string   $flagName Flag identifier (e.g. 'new-checkout').
     * @param int|null $userId   User ID for per-user overrides and rollout bucketing.
     *                           Pass null to skip user-level checks.
     *
     * @return bool True if the flag is enabled for this context.
     */
    public function isEnabled(string $flagName, ?int $userId = null): bool
    {
        // 1. Per-user override
        if ($userId !== null) {
            $override = $this->findOverride($flagName, $userId);
            if ($override !== null) {
                return $override;
            }
        }

        // 2. Global flag
        $flag = $this->findFlag($flagName);
        if ($flag === null) {
            return false;   // flag doesn't exist → off
        }

        if ($flag['is_enabled']) {
            return true;
        }

        // 3. Rollout percentage (only with a userId)
        if ($userId !== null && $flag['rollout_pct'] > 0) {
            $bucket = abs(crc32($userId . '.' . $flagName)) % 100;
            return $bucket < $flag['rollout_pct'];
        }

        return false;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * @return bool|null Override value, or null if no override exists.
     */
    private function findOverride(string $flagName, int $userId): ?bool
    {
        $stmt = $this->db->prepare(
            'SELECT is_enabled FROM flag_overrides WHERE flag_name = :flag AND user_id = :uid LIMIT 1'
        );
        $stmt->bindValue(':flag', $flagName, PDO::PARAM_STR);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return (bool)$row['is_enabled'];
        }
        return null;
    }

    /**
     * @return array{is_enabled: bool, rollout_pct: int}|null
     */
    private function findFlag(string $flagName): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT is_enabled, rollout_pct FROM feature_flags WHERE flag_name = :flag LIMIT 1'
        );
        $stmt->bindValue(':flag', $flagName, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'is_enabled'  => (bool)$row['is_enabled'],
            'rollout_pct' => (int)($row['rollout_pct'] ?? 0),
        ];
    }
}
