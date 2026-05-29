<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Quota — plan-based resource quota management.
 *
 * Tracks usage of named resources (e.g. "api_calls", "storage_bytes") per user
 * against a configured limit. Supports soft-reset via expiry windows.
 *
 * ## Usage
 *
 * ```php
 * $q = new Quota($pdo);
 *
 * // Set a quota limit for a user
 * $q->grant('user-1', 'api_calls', 1000, ttlSeconds: 86400);
 *
 * // Consume (returns false if quota exceeded)
 * $ok = $q->consume('user-1', 'api_calls', 1);
 *
 * // Check usage
 * ['used' => $used, 'quota' => $max] = $q->check('user-1', 'api_calls');
 *
 * // Reset usage
 * $q->reset('user-1', 'api_calls');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_quotas (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     resource   VARCHAR(100) NOT NULL,
 *     used       BIGINT       NOT NULL DEFAULT 0,
 *     quota      BIGINT       NOT NULL DEFAULT 0,
 *     resets_at  DATETIME     NULL,
 *     updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, resource)
 * );
 * ```
 */
final class Quota
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Grant (or update) a quota limit for a user+resource.
     *
     * @param int  $quota      Maximum allowed usage (0 = unlimited).
     * @param int  $ttlSeconds Seconds until quota window resets (0 = no auto-reset).
     * @throws \InvalidArgumentException on empty userId/resource.
     */
    public function grant(string $userId, string $resource, int $quota, int $ttlSeconds = 0): void
    {
        [$userId, $resource] = $this->validateInputs($userId, $resource);
        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $resets   = $ttlSeconds > 0
            ? (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s')
            : null;
        $db       = $this->db();

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO user_quotas (user_id, resource, quota, resets_at, updated_at)
                 VALUES (:uid, :res, :quota, :resets, :now)
                 ON CONFLICT (user_id, resource)
                 DO UPDATE SET quota      = excluded.quota,
                               resets_at  = excluded.resets_at,
                               updated_at = excluded.updated_at'
            )->execute([':uid' => $userId, ':res' => $resource, ':quota' => $quota, ':resets' => $resets, ':now' => $now]);
        } else {
            $db->prepare(
                'INSERT INTO user_quotas (user_id, resource, quota, resets_at, updated_at)
                 VALUES (:uid, :res, :quota, :resets, :now)
                 ON DUPLICATE KEY UPDATE quota      = VALUES(quota),
                                         resets_at  = VALUES(resets_at),
                                         updated_at = VALUES(updated_at)'
            )->execute([':uid' => $userId, ':res' => $resource, ':quota' => $quota, ':resets' => $resets, ':now' => $now]);
        }
    }

    /**
     * Consume amount units of the resource.
     *
     * Returns true if the consumption was allowed (used <= quota or quota = 0).
     * Returns false if quota would be exceeded.
     * Auto-resets the window if resets_at has passed.
     *
     * @param int $amount Units to consume (must be > 0).
     */
    public function consume(string $userId, string $resource, int $amount = 1): bool
    {
        [$userId, $resource] = $this->validateInputs($userId, $resource);
        $row = $this->fetchRow($userId, $resource);

        if ($row === null) {
            return true; // no quota configured → allow
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $used = (int)$row['used'];
        $max  = (int)$row['quota'];

        // Auto-reset if window expired
        if ($row['resets_at'] !== null && $row['resets_at'] <= $now) {
            $used = 0;
            $this->db()->prepare(
                'UPDATE user_quotas SET used = 0, updated_at = :now WHERE user_id = :uid AND resource = :res'
            )->execute([':now' => $now, ':uid' => $userId, ':res' => $resource]);
        }

        // 0 = unlimited
        if ($max > 0 && ($used + $amount) > $max) {
            return false;
        }

        $this->db()->prepare(
            'UPDATE user_quotas SET used = used + :amount, updated_at = :now
             WHERE user_id = :uid AND resource = :res'
        )->execute([':amount' => $amount, ':now' => $now, ':uid' => $userId, ':res' => $resource]);

        return true;
    }

    /**
     * Check current usage for a user+resource.
     *
     * @return array{used:int,quota:int,resets_at:string|null}|null  Null if no quota configured.
     */
    public function check(string $userId, string $resource): ?array
    {
        [$userId, $resource] = $this->validateInputs($userId, $resource);
        $row = $this->fetchRow($userId, $resource);
        if ($row === null) {
            return null;
        }
        return [
            'used'      => (int)$row['used'],
            'quota'     => (int)$row['quota'],
            'resets_at' => $row['resets_at'],
        ];
    }

    /**
     * Returns true if the user has exceeded their quota.
     */
    public function exceeded(string $userId, string $resource): bool
    {
        $info = $this->check($userId, $resource);
        if ($info === null || $info['quota'] === 0) {
            return false;
        }
        return $info['used'] >= $info['quota'];
    }

    /**
     * Reset usage counter for a user+resource to zero.
     */
    public function reset(string $userId, string $resource): bool
    {
        [$userId, $resource] = $this->validateInputs($userId, $resource);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE user_quotas SET used = 0, updated_at = :now
             WHERE user_id = :uid AND resource = :res'
        );
        $stmt->execute([':now' => $now, ':uid' => $userId, ':res' => $resource]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all quota rows for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function usage(string $userId): array
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare(
            'SELECT id, user_id, resource, used, quota, resets_at, updated_at
             FROM user_quotas WHERE user_id = :uid ORDER BY resource ASC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Remove quota configuration for a user+resource.
     */
    public function revoke(string $userId, string $resource): bool
    {
        [$userId, $resource] = $this->validateInputs($userId, $resource);
        $stmt = $this->db()->prepare(
            'DELETE FROM user_quotas WHERE user_id = :uid AND resource = :res'
        );
        $stmt->execute([':uid' => $userId, ':res' => $resource]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string,string}
     */
    private function validateInputs(string $userId, string $resource): array
    {
        $userId   = trim($userId);
        $resource = trim($resource);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($resource === '') {
            throw new \InvalidArgumentException('resource must not be empty.');
        }
        return [$userId, $resource];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchRow(string $userId, string $resource): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, resource, used, quota, resets_at, updated_at
             FROM user_quotas WHERE user_id = :uid AND resource = :res LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':res' => $resource]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
