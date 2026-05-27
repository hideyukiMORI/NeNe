<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * AccessToken — issue, verify, and revoke personal access tokens (PATs).
 *
 * Tokens are 32-byte random hex strings (64 chars). Only the SHA-256 hash is stored.
 * The raw token is returned once at creation and never again. Tokens can be scoped
 * and have optional expiry.
 *
 * ## Usage
 *
 * ```php
 * $at = new AccessToken($pdo);
 *
 * // Issue a token
 * $token = $at->issue('user-1', 'My CI token', ['repo:read', 'deploy:push']);
 *
 * // Verify incoming request token
 * $info = $at->verify($token); // null if invalid/revoked/expired
 *
 * // List tokens for a user (no raw tokens, just metadata)
 * $at->listForUser('user-1');
 *
 * // Revoke by token
 * $at->revoke($token);
 *
 * // Revoke all tokens for a user
 * $at->revokeAll('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE access_tokens (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     name        VARCHAR(255) NOT NULL DEFAULT '',
 *     token_hash  VARCHAR(64)  NOT NULL UNIQUE,
 *     scopes      TEXT         NOT NULL DEFAULT '[]',
 *     last_used   DATETIME     DEFAULT NULL,
 *     expires_at  DATETIME     DEFAULT NULL,
 *     revoked_at  DATETIME     DEFAULT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AccessToken
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Issue a new personal access token.
     *
     * @param  list<string>           $scopes    Optional list of permission scopes.
     * @param  \DateTimeImmutable|null $expiresAt Optional expiry time.
     * @return string  The raw token (64 hex chars). Store it now — it won't be shown again.
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function issue(
        string $userId,
        string $name = '',
        array $scopes = [],
        ?\DateTimeImmutable $expiresAt = null
    ): string {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        $rawToken  = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $rawToken);
        $scopeJson = json_encode(array_values($scopes), JSON_UNESCAPED_UNICODE) ?: '[]';
        $expireStr = $expiresAt?->format('Y-m-d H:i:s');

        $this->db()->prepare(
            'INSERT INTO access_tokens (user_id, name, token_hash, scopes, expires_at)
             VALUES (:uid, :name, :hash, :scopes, :exp)'
        )->execute([':uid' => $userId, ':name' => $name, ':hash' => $hash, ':scopes' => $scopeJson, ':exp' => $expireStr]);

        return $rawToken;
    }

    /**
     * Verify a token and return its metadata if valid.
     *
     * Updates last_used on successful verification.
     *
     * @return array<string,mixed>|null  null if token is invalid, revoked, or expired.
     */
    public function verify(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'SELECT id, user_id, name, scopes, expires_at, last_used, created_at
             FROM access_tokens
             WHERE token_hash = :hash
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > :now)
             LIMIT 1'
        );
        $stmt->execute([':hash' => $hash, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // Touch last_used
        $this->db()->prepare(
            'UPDATE access_tokens SET last_used = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':id' => $row['id']]);

        $decoded = json_decode((string)($row['scopes'] ?? '[]'), true);
        $row['scopes'] = is_array($decoded) ? $decoded : [];

        return $row;
    }

    /**
     * Check if a raw token is currently valid (non-revoked, non-expired).
     */
    public function isValid(string $rawToken): bool
    {
        $hash = hash('sha256', $rawToken);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM access_tokens
             WHERE token_hash = :hash AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > :now)'
        );
        $stmt->execute([':hash' => $hash, ':now' => $now]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Revoke a specific token by its raw value.
     *
     * @return bool True if the token was found and revoked.
     */
    public function revoke(string $rawToken): bool
    {
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db()->prepare(
            'UPDATE access_tokens SET revoked_at = CURRENT_TIMESTAMP
             WHERE token_hash = :hash AND revoked_at IS NULL'
        );
        $stmt->execute([':hash' => $hash]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke all tokens for a user.
     *
     * @return int Number of tokens revoked.
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function revokeAll(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare(
            'UPDATE access_tokens SET revoked_at = CURRENT_TIMESTAMP
             WHERE user_id = :uid AND revoked_at IS NULL'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * List all tokens for a user (metadata only — no raw tokens).
     *
     * @return list<array<string,mixed>>
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function listForUser(string $userId, bool $includeRevoked = false): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        if ($includeRevoked) {
            $stmt = $this->db()->prepare(
                'SELECT id, user_id, name, scopes, last_used, expires_at, revoked_at, created_at
                 FROM access_tokens WHERE user_id = :uid ORDER BY id DESC'
            );
            $stmt->execute([':uid' => $userId]);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, user_id, name, scopes, last_used, expires_at, revoked_at, created_at
                 FROM access_tokens WHERE user_id = :uid AND revoked_at IS NULL ORDER BY id DESC'
            );
            $stmt->execute([':uid' => $userId]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string)($row['scopes'] ?? '[]'), true);
            $row['scopes'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    /**
     * Purge revoked and expired tokens older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM access_tokens
             WHERE created_at < :cutoff
               AND (revoked_at IS NOT NULL OR (expires_at IS NOT NULL AND expires_at <= :now))'
        );
        $stmt->execute([':cutoff' => $cutoff, ':now' => $now]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
