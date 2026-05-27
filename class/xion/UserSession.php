<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * UserSession — DB-backed server-side session store with TTL and payload.
 *
 * Complements cookie/JWT auth with a server-side record of active sessions.
 * Useful for logout-all, concurrent session limits, and session audit.
 *
 * ## Usage
 *
 * ```php
 * $us = new UserSession($pdo, ttlSeconds: 3600);
 *
 * // Create session
 * $token = $us->create('user-1', ['ip' => '1.2.3.4', 'ua' => 'Mozilla/...']);
 *
 * // Validate and refresh
 * $info = $us->validate($token); // null if invalid/expired
 *
 * // List active sessions
 * $us->listForUser('user-1');
 *
 * // Terminate
 * $us->revoke($token);
 * $us->revokeAll('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_sessions (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     token_hash  VARCHAR(64)  NOT NULL UNIQUE,
 *     payload     TEXT         NOT NULL DEFAULT '{}',
 *     expires_at  DATETIME     NOT NULL,
 *     last_active DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class UserSession
{
    public function __construct(
        private readonly ?PDO $db = null,
        private readonly int $ttlSeconds = 3600,
    ) {
        if ($this->ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttlSeconds must be positive.');
        }
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new session for a user.
     *
     * @param  array<string,mixed> $payload  Optional metadata (IP, UA, etc.) stored as JSON.
     * @return string  The raw session token (32-byte hex, 64 chars). Store in cookie/header.
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function create(string $userId, array $payload = []): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        $rawToken   = bin2hex(random_bytes(32));
        $hash       = hash('sha256', $rawToken);
        $payloadStr = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}';
        $expiresAt  = (new \DateTimeImmutable())->modify("+{$this->ttlSeconds} seconds")->format('Y-m-d H:i:s');

        $this->db()->prepare(
            'INSERT INTO user_sessions (user_id, token_hash, payload, expires_at)
             VALUES (:uid, :hash, :payload, :exp)'
        )->execute([':uid' => $userId, ':hash' => $hash, ':payload' => $payloadStr, ':exp' => $expiresAt]);

        return $rawToken;
    }

    /**
     * Validate a session token and refresh its TTL.
     *
     * @return array<string,mixed>|null  Session info, or null if invalid/expired.
     */
    public function validate(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'SELECT id, user_id, payload, expires_at, last_active, created_at
             FROM user_sessions
             WHERE token_hash = :hash AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([':hash' => $hash, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // Refresh TTL
        $newExpiry = (new \DateTimeImmutable())->modify("+{$this->ttlSeconds} seconds")->format('Y-m-d H:i:s');
        $this->db()->prepare(
            'UPDATE user_sessions SET expires_at = :exp, last_active = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':exp' => $newExpiry, ':id' => $row['id']]);

        $decoded = json_decode((string)($row['payload'] ?? '{}'), true);
        $row['payload'] = is_array($decoded) ? $decoded : [];

        return $row;
    }

    /**
     * Check if a session token is currently valid (without refreshing TTL).
     */
    public function isValid(string $rawToken): bool
    {
        $hash = hash('sha256', $rawToken);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM user_sessions WHERE token_hash = :hash AND expires_at > :now'
        );
        $stmt->execute([':hash' => $hash, ':now' => $now]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Revoke a specific session.
     *
     * @return bool True if the session existed and was deleted.
     */
    public function revoke(string $rawToken): bool
    {
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db()->prepare('DELETE FROM user_sessions WHERE token_hash = :hash');
        $stmt->execute([':hash' => $hash]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke all sessions for a user.
     *
     * @return int Number of sessions revoked.
     */
    public function revokeAll(string $userId): int
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare('DELETE FROM user_sessions WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * List all active (non-expired) sessions for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(string $userId): array
    {
        $userId = trim($userId);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT id, user_id, payload, expires_at, last_active, created_at
             FROM user_sessions WHERE user_id = :uid AND expires_at > :now
             ORDER BY last_active DESC'
        );
        $stmt->execute([':uid' => $userId, ':now' => $now]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string)($row['payload'] ?? '{}'), true);
            $row['payload'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    /**
     * Count active sessions for a user.
     */
    public function countForUser(string $userId): int
    {
        $userId = trim($userId);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = :uid AND expires_at > :now'
        );
        $stmt->execute([':uid' => $userId, ':now' => $now]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete expired sessions.
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare('DELETE FROM user_sessions WHERE expires_at <= :now');
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
