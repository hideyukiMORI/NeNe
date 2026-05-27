<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Device trust / Remember-Me token manager.
 *
 * Issues long-lived opaque tokens that identify a trusted device or browser session.
 * Raw token is shown once; only its SHA-256 hash is stored.
 * Tokens expire and can be revoked individually or all at once per user.
 *
 * ## Usage
 *
 * ```php
 * $dt = new DeviceToken($pdo);
 *
 * // Issue a 30-day remember-me token
 * $raw = $dt->issue('user-1', ttlDays: 30, label: 'MacBook Chrome');
 * // Store $raw in a cookie; the class stores only its hash.
 *
 * // Validate on next visit
 * $result = $dt->validate($raw);
 * if ($result !== null) {
 *     // $result['user_id'] is the authenticated user
 * }
 *
 * // Revoke one token
 * $dt->revoke($raw);
 *
 * // Revoke all tokens for a user (e.g. on password change)
 * $dt->revokeAll('user-1');
 *
 * // List active tokens for a user (for "logged-in devices" UI)
 * $dt->list('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE device_tokens (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     token_hash  VARCHAR(64)  NOT NULL UNIQUE,
 *     label       VARCHAR(255) NOT NULL DEFAULT '',
 *     expires_at  DATETIME     NOT NULL,
 *     last_used_at DATETIME    DEFAULT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class DeviceToken
{
    private const DEFAULT_TTL_DAYS = 30;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Issue a new device/remember-me token.
     *
     * @param  string $userId  User to associate the token with.
     * @param  int    $ttlDays Number of days until the token expires (default 30).
     * @param  string $label   Human-readable device label (e.g. 'MacBook Chrome').
     * @return string          Raw token — store in a secure HTTP-only cookie.
     */
    public function issue(string $userId, int $ttlDays = self::DEFAULT_TTL_DAYS, string $label = ''): string
    {
        $raw       = bin2hex(random_bytes(32)); // 64 hex chars
        $hash      = hash('sha256', $raw);
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlDays * 86400);

        $this->db()->prepare(
            'INSERT INTO device_tokens (user_id, token_hash, label, expires_at)
             VALUES (:user, :hash, :label, :expires)'
        )->execute([
            ':user'    => $userId,
            ':hash'    => $hash,
            ':label'   => trim($label),
            ':expires' => $expiresAt,
        ]);

        return $raw;
    }

    /**
     * Validate a raw token.
     *
     * Checks expiry and updates `last_used_at` on success.
     *
     * @param  string $rawToken Raw token from the cookie.
     * @return array<string, mixed>|null Full token row (with user_id), or null if invalid/expired.
     */
    public function validate(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);
        $db   = $this->db();

        $stmt = $db->prepare(
            'SELECT * FROM device_tokens
             WHERE token_hash = :hash AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // Update last_used_at
        $db->prepare(
            'UPDATE device_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE token_hash = :hash'
        )->execute([':hash' => $hash]);

        return $row;
    }

    /**
     * Revoke a single token by raw value.
     *
     * @return bool True if a token was found and deleted.
     */
    public function revoke(string $rawToken): bool
    {
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db()->prepare('DELETE FROM device_tokens WHERE token_hash = :hash');
        $stmt->execute([':hash' => $hash]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke all tokens for a user (e.g. on password change or account compromise).
     *
     * @return int Number of tokens revoked.
     */
    public function revokeAll(string $userId): int
    {
        $stmt = $this->db()->prepare('DELETE FROM device_tokens WHERE user_id = :user');
        $stmt->execute([':user' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * List all non-expired tokens for a user (for "trusted devices" UI).
     *
     * Note: token hashes are NOT included in the returned rows.
     *
     * @return list<array{id: int, label: string, expires_at: string, last_used_at: string|null, created_at: string}>
     */
    public function list(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, label, expires_at, last_used_at, created_at
             FROM device_tokens
             WHERE user_id = :user AND expires_at > CURRENT_TIMESTAMP
             ORDER BY created_at DESC'
        );
        $stmt->execute([':user' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Purge all expired tokens for a user (or all users if userId is empty).
     *
     * @return int Number of tokens purged.
     */
    public function purgeExpired(string $userId = ''): int
    {
        if ($userId !== '') {
            $stmt = $this->db()->prepare(
                'DELETE FROM device_tokens WHERE user_id = :user AND expires_at <= CURRENT_TIMESTAMP'
            );
            $stmt->execute([':user' => $userId]);
        } else {
            $stmt = $this->db()->prepare('DELETE FROM device_tokens WHERE expires_at <= CURRENT_TIMESTAMP');
            $stmt->execute();
        }
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
