<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * DB-backed refresh token management for JWT access token rotation.
 *
 * Pair with JwtCodec: access tokens are short-lived JWTs (stateless),
 * refresh tokens are long-lived random values stored as SHA-256 hashes.
 *
 * ## Token lifecycle
 *
 * ```
 * Login  → issue()  → returns rawToken (client keeps this)
 * Refresh → rotate() → verifies + revokes old, issues new rawToken
 * Logout  → revoke() → marks token revoked (always 204, no oracle)
 * ```
 *
 * ## Replay attack detection
 *
 * If rotate() receives a **revoked** token (already rotated), it assumes the
 * token was stolen and calls revokeAll() to invalidate all tokens for that
 * user. The attacker's stolen token produces no new token; the legitimate
 * user is forced to re-authenticate.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE refresh_tokens (
 *     id         INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     token_hash VARCHAR(64)  NOT NULL UNIQUE,  -- SHA-256 of raw token
 *     expires_at DATETIME     NOT NULL,
 *     revoked_at DATETIME     DEFAULT NULL,     -- NULL = active
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_refresh_tokens_user ON refresh_tokens (user_id);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $codec   = new JwtCodec(getenv('NENE_JWT_SECRET'), ttl: 300); // 5-minute access tokens
 * $refresh = new RefreshToken();
 *
 * // Login
 * $accessToken  = $codec->issue(['sub' => $userId, 'jti' => bin2hex(random_bytes(8))]);
 * $refreshToken = $refresh->issue($userId, 7 * 86400);
 * // Return both to the client.
 *
 * // Token refresh
 * $result = $refresh->rotate($incomingRefreshToken);
 * if ($result === null) {
 *     // Invalid, expired, or replay attack — force re-login
 *     http_response_code(401); exit;
 * }
 * ['rawToken' => $newRefreshToken, 'userId' => $userId] = $result;
 * $newAccessToken = $codec->issue(['sub' => $userId, 'jti' => bin2hex(random_bytes(8))]);
 *
 * // Logout (always 204 — do not reveal token validity)
 * $refresh->revoke($incomingRefreshToken);
 * http_response_code(204); exit;
 * ```
 */
final class RefreshToken
{
    private const TABLE        = 'refresh_tokens';
    private const DEFAULT_TTL  = 7 * 86400; // 7 days

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Issue a new refresh token for the given user.
     *
     * Returns the raw token (show to the client once — never stored).
     *
     * @param string|int $userId     Application-level user identifier.
     * @param int        $ttlSeconds Token lifetime in seconds.
     */
    public function issue(string|int $userId, int $ttlSeconds = self::DEFAULT_TTL): string
    {
        $rawToken  = bin2hex(random_bytes(32)); // 64-char hex, 256-bit entropy
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $ttlSeconds . ' seconds')
            ->format('Y-m-d H:i:s');

        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (user_id, token_hash, expires_at) VALUES (:u, :h, :e)'
        );
        $stmt->bindValue(':u', (string)$userId, PDO::PARAM_STR);
        $stmt->bindValue(':h', $tokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':e', $expiresAt, PDO::PARAM_STR);
        $stmt->execute();

        return $rawToken;
    }

    /**
     * Rotate a refresh token: verify, revoke old, issue new.
     *
     * Returns ['rawToken' => ..., 'userId' => ...] on success.
     * Returns null if the token is invalid or expired.
     *
     * ⚠ Replay attack: if a *revoked* token is presented, ALL tokens for
     * that user are revoked immediately. The caller should force re-login.
     *
     * @param  string $rawToken Raw token received from the client.
     * @return array{rawToken: string, userId: string}|null
     */
    public function rotate(string $rawToken): ?array
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $tokenHash = hash('sha256', $rawToken);
        $now       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'SELECT id, user_id, expires_at, revoked_at FROM ' . $this->table .
            ' WHERE token_hash = :h LIMIT 1'
        );
        $stmt->bindValue(':h', $tokenHash, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            // Unknown token
            return null;
        }

        $userId    = (string)$row['user_id'];
        $expiresAt = (string)$row['expires_at'];
        $revokedAt = $row['revoked_at'];

        if ($revokedAt !== null) {
            // Already revoked — replay attack detected. Revoke everything for this user.
            $this->revokeAll($userId);
            return null;
        }

        if ($expiresAt <= $now) {
            // Expired
            return null;
        }

        // Valid — revoke old token and issue new one
        $this->revokeById((int)$row['id']);
        $newRawToken = $this->issue($userId);

        return ['rawToken' => $newRawToken, 'userId' => $userId];
    }

    /**
     * Revoke a single refresh token (for logout).
     *
     * Always succeeds silently even if the token is already revoked or unknown.
     * Callers should return 204 unconditionally to avoid revealing token state.
     *
     * @param string $rawToken Raw token received from the client.
     */
    public function revoke(string $rawToken): void
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $tokenHash = hash('sha256', $rawToken);
        $revokedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET revoked_at = :t WHERE token_hash = :h AND revoked_at IS NULL'
        );
        $stmt->bindValue(':t', $revokedAt, PDO::PARAM_STR);
        $stmt->bindValue(':h', $tokenHash, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Revoke all active refresh tokens for a user (replay attack response or forced logout).
     *
     * @param string|int $userId Application-level user identifier.
     */
    public function revokeAll(string|int $userId): void
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $revokedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET revoked_at = :t WHERE user_id = :u AND revoked_at IS NULL'
        );
        $stmt->bindValue(':t', $revokedAt, PDO::PARAM_STR);
        $stmt->bindValue(':u', (string)$userId, PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * Hash a raw token for DB lookup (exposed for testing convenience).
     */
    public function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    private function revokeById(int $id): void
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $revokedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table . ' SET revoked_at = :t WHERE id = :id'
        );
        $stmt->bindValue(':t', $revokedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }
}
