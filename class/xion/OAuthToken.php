<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * OAuthToken — OAuth2 access/refresh token storage with TTL.
 *
 * Stores access tokens and their paired refresh tokens for OAuth2 flows.
 * Tokens are stored as SHA-256 hashes; raw values are never persisted.
 *
 * ## Usage
 *
 * ```php
 * $ot = new OAuthToken($pdo);
 *
 * // Issue token pair after authorization
 * [$accessRaw, $refreshRaw] = $ot->issue('user-1', 'client-app', ['read', 'write'], 3600, 2592000);
 *
 * // Verify access token (bearer header)
 * $record = $ot->verifyAccess($accessRaw);
 * if ($record === null) { /* expired or invalid *\/ }
 *
 * // Rotate tokens via refresh
 * [$newAccess, $newRefresh] = $ot->refresh($refreshRaw, 3600, 2592000);
 *
 * // Revoke all tokens for a user
 * $ot->revokeAllForUser('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE oauth_tokens (
 *     id                INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id           VARCHAR(255) NOT NULL,
 *     client_id         VARCHAR(255) NOT NULL DEFAULT '',
 *     scope             TEXT         NOT NULL DEFAULT '',
 *     access_hash       VARCHAR(64)  NOT NULL UNIQUE,
 *     refresh_hash      VARCHAR(64)  NOT NULL UNIQUE,
 *     access_expires_at DATETIME     NOT NULL,
 *     refresh_expires_at DATETIME    NOT NULL,
 *     revoked           TINYINT(1)   NOT NULL DEFAULT 0,
 *     issued_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class OAuthToken
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Issue a new access/refresh token pair.
     *
     * @param  string   $userId              The authenticated user.
     * @param  string   $clientId            OAuth client identifier.
     * @param  string[] $scopes              Granted scopes.
     * @param  int      $accessTtlSeconds    Access token lifetime in seconds (default 1 h).
     * @param  int      $refreshTtlSeconds   Refresh token lifetime in seconds (default 30 d).
     * @return array{string, string}         [rawAccessToken, rawRefreshToken]
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function issue(
        string $userId,
        string $clientId = '',
        array $scopes = [],
        int $accessTtlSeconds = 3600,
        int $refreshTtlSeconds = 2592000
    ): array {
        $userId = $this->validateUserId($userId);

        $rawAccess  = bin2hex(random_bytes(32));
        $rawRefresh = bin2hex(random_bytes(32));

        $now              = new \DateTimeImmutable();
        $accessExpiresAt  = $now->modify("+{$accessTtlSeconds} seconds")->format('Y-m-d H:i:s');
        $refreshExpiresAt = $now->modify("+{$refreshTtlSeconds} seconds")->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'INSERT INTO oauth_tokens
                (user_id, client_id, scope, access_hash, refresh_hash, access_expires_at, refresh_expires_at)
             VALUES (:uid, :cid, :scope, :ah, :rh, :aexp, :rexp)'
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':cid'   => $clientId,
            ':scope' => implode(' ', $scopes),
            ':ah'    => hash('sha256', $rawAccess),
            ':rh'    => hash('sha256', $rawRefresh),
            ':aexp'  => $accessExpiresAt,
            ':rexp'  => $refreshExpiresAt,
        ]);

        return [$rawAccess, $rawRefresh];
    }

    /**
     * Verify an access token and return the record, or null if invalid/expired/revoked.
     *
     * @return array<string,mixed>|null
     */
    public function verifyAccess(string $rawToken): ?array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, client_id, scope, access_expires_at, issued_at
             FROM oauth_tokens
             WHERE access_hash = :hash AND access_expires_at > :now AND revoked = 0
             LIMIT 1'
        );
        $stmt->execute([':hash' => $hash, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Rotate tokens using a valid refresh token.
     *
     * Revokes the old token pair and issues a new one.
     * Returns null if the refresh token is invalid, expired, or revoked.
     *
     * @param  int $accessTtlSeconds   New access token lifetime.
     * @param  int $refreshTtlSeconds  New refresh token lifetime.
     * @return array{string, string}|null  [rawAccessToken, rawRefreshToken] or null.
     */
    public function refresh(
        string $rawRefreshToken,
        int $accessTtlSeconds = 3600,
        int $refreshTtlSeconds = 2592000
    ): ?array {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $hash = hash('sha256', $rawRefreshToken);

        $stmt = $this->db()->prepare(
            'SELECT id, user_id, client_id, scope
             FROM oauth_tokens
             WHERE refresh_hash = :hash AND refresh_expires_at > :now AND revoked = 0
             LIMIT 1'
        );
        $stmt->execute([':hash' => $hash, ':now' => $now]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($old === false) {
            return null;
        }

        // Revoke old pair
        $this->revokeById((int)$old['id']);

        // Issue new pair with same user/client/scope
        $scopes = $old['scope'] !== '' ? explode(' ', (string)$old['scope']) : [];
        return $this->issue(
            (string)$old['user_id'],
            (string)$old['client_id'],
            $scopes,
            $accessTtlSeconds,
            $refreshTtlSeconds
        );
    }

    /**
     * Revoke a specific access token.
     *
     * @return bool True if the token existed and was revoked.
     */
    public function revokeAccess(string $rawToken): bool
    {
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db()->prepare(
            'UPDATE oauth_tokens SET revoked = 1 WHERE access_hash = :hash AND revoked = 0'
        );
        $stmt->execute([':hash' => $hash]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke all active tokens for a user.
     *
     * @return int Number of tokens revoked.
     */
    public function revokeAllForUser(string $userId): int
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'UPDATE oauth_tokens SET revoked = 1 WHERE user_id = :uid AND revoked = 0'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * Count active (non-revoked, non-expired) token pairs for a user.
     */
    public function activeCount(string $userId): int
    {
        $userId = $this->validateUserId($userId);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM oauth_tokens
             WHERE user_id = :uid AND revoked = 0 AND refresh_expires_at > :now'
        );
        $stmt->execute([':uid' => $userId, ':now' => $now]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete expired and revoked tokens (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'DELETE FROM oauth_tokens WHERE revoked = 1 OR refresh_expires_at <= :now'
        );
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }

    private function revokeById(int $id): void
    {
        $this->db()->prepare('UPDATE oauth_tokens SET revoked = 1 WHERE id = :id')
            ->execute([':id' => $id]);
    }
}
