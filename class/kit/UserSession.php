<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * UserSession — multi-device application session management.
 *
 * Tracks application-level sessions independently of PHP's built-in session
 * mechanism. Useful for multi-device management (force logout from all
 * devices), remember-me flows, and concurrent session auditing.
 *
 * Tokens are stored as SHA-256 hashes; the raw token is returned only at
 * creation time and never stored. Expiry is enforced at lookup time.
 *
 * ## Usage
 *
 * ```php
 * $us = new UserSession($pdo);
 *
 * // Login
 * ['id' => $id, 'token' => $raw] = $us->create('user-42', 'Mozilla/5.0', '1.2.3.4', 86400);
 * // Store $raw in cookie; never store in DB.
 *
 * // Validate on each request
 * $session = $us->findByToken($raw);
 * if ($session === null) { /* expired or invalid * / }
 *
 * // Heartbeat
 * $us->touch($id);
 *
 * // Logout one device
 * $us->invalidate($id);
 *
 * // Logout all devices
 * $us->invalidateAll('user-42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_sessions (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     token_hash   VARCHAR(64)  NOT NULL UNIQUE,
 *     device_info  TEXT         NULL,
 *     ip_address   VARCHAR(45)  NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'active',
 *     last_active  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     expires_at   DATETIME     NOT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class UserSession
{
    public const STATUS_ACTIVE      = 'active';
    public const STATUS_INVALIDATED = 'invalidated';
    public const STATUS_EXPIRED     = 'expired';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new session and return the raw token with the row ID.
     *
     * @param int $ttlSeconds Lifetime in seconds (must be >= 1).
     * @return array{id: int, token: string} id = row ID; token = raw (unhashed) token.
     * @throws \InvalidArgumentException on empty userId or invalid ttl.
     */
    public function create(
        string $userId,
        ?string $deviceInfo = null,
        ?string $ipAddress = null,
        int $ttlSeconds = 86400
    ): array {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('ttlSeconds must be >= 1.');
        }

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $now       = new \DateTimeImmutable();
        $expiresAt = $now->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');
        $nowStr    = $now->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'INSERT INTO user_sessions (user_id, token_hash, device_info, ip_address, status, last_active, expires_at, created_at)
             VALUES (:uid, :hash, :device, :ip, :status, :now, :exp, :now)'
        );
        $stmt->execute([
            ':uid'    => $userId,
            ':hash'   => $tokenHash,
            ':device' => $deviceInfo,
            ':ip'     => $ipAddress,
            ':status' => self::STATUS_ACTIVE,
            ':now'    => $nowStr,
            ':exp'    => $expiresAt,
        ]);

        return ['id' => (int)$this->db()->lastInsertId(), 'token' => $rawToken];
    }

    /**
     * Look up an active, non-expired session by raw token.
     *
     * Returns null when the token is unknown, session is invalidated, or
     * expired. Automatically marks expired sessions as STATUS_EXPIRED.
     *
     * @return array<string,mixed>|null
     */
    public function findByToken(string $rawToken): ?array
    {
        $tokenHash = hash('sha256', $rawToken);
        $stmt      = $this->db()->prepare(
            'SELECT * FROM user_sessions WHERE token_hash = :hash'
        );
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if ($row['status'] !== self::STATUS_ACTIVE) {
            return null;
        }
        // Expire check
        $now = new \DateTimeImmutable();
        if (new \DateTimeImmutable((string)$row['expires_at']) < $now) {
            $this->markExpired((int)$row['id']);
            return null;
        }
        return $row;
    }

    /**
     * Retrieve a session row by ID (for admin/audit use).
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM user_sessions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Update last_active timestamp for a session (keep-alive).
     *
     * @return bool True if found and updated.
     */
    public function touch(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE user_sessions SET last_active = :now WHERE id = :id AND status = :status'
        );
        $stmt->execute([':now' => $now, ':id' => $id, ':status' => self::STATUS_ACTIVE]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Invalidate a single session (logout one device).
     *
     * @return bool True if found and invalidated.
     */
    public function invalidate(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE user_sessions SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_INVALIDATED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Invalidate all active sessions for a user (force logout all devices).
     *
     * @return int Number of sessions invalidated.
     */
    public function invalidateAll(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'UPDATE user_sessions SET status = :status
             WHERE user_id = :uid AND status = :active'
        );
        $stmt->execute([
            ':status' => self::STATUS_INVALIDATED,
            ':uid'    => $userId,
            ':active' => self::STATUS_ACTIVE,
        ]);
        return $stmt->rowCount();
    }

    /**
     * Return all active sessions for a user (for session list UI).
     *
     * @return list<array<string,mixed>>
     */
    public function activeSessions(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, device_info, ip_address, status, last_active, expires_at, created_at
             FROM user_sessions
             WHERE user_id = :uid AND status = :status AND expires_at > :now
             ORDER BY last_active DESC'
        );
        $stmt->execute([':uid' => $userId, ':status' => self::STATUS_ACTIVE, ':now' => $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete sessions that have been expired or invalidated before $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM user_sessions
             WHERE status != :active AND created_at < :cutoff'
        );
        $stmt->execute([':active' => self::STATUS_ACTIVE, ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function markExpired(int $id): void
    {
        $this->db()->prepare('UPDATE user_sessions SET status = :status WHERE id = :id')
            ->execute([':status' => self::STATUS_EXPIRED, ':id' => $id]);
    }
}
