<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Personal Access Token (PAT) management for user-facing dashboards.
 *
 * PATs are long-lived, user-managed credentials — analogous to GitHub PATs.
 * Each token carries a named set of abilities, and authenticate() records
 * the last-used timestamp for auditability.
 *
 * Token format: `pat_{base64url(32 random bytes)}` — 256-bit entropy, type-prefixed.
 * Storage: only the SHA-256 hash is stored. The raw token is returned once at creation.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE personal_access_tokens (
 *     id           INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     prefix       VARCHAR(16)  NOT NULL,
 *     token_hash   VARCHAR(64)  NOT NULL UNIQUE,
 *     user_id      VARCHAR(255) NOT NULL,
 *     name         VARCHAR(255) NOT NULL DEFAULT '',
 *     abilities    TEXT         NOT NULL DEFAULT '*',
 *     expires_at   DATETIME     DEFAULT NULL,
 *     last_used_at DATETIME     DEFAULT NULL,
 *     revoked_at   DATETIME     DEFAULT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_personal_access_tokens_prefix ON personal_access_tokens (prefix);
 * ```
 *
 * ## Abilities
 *
 * Abilities are stored as a JSON array, e.g. `["read","write"]`.
 * The wildcard string `'*'` (stored verbatim) grants all abilities.
 * `authenticate($token, 'write')` succeeds if abilities contains `'*'` or `'write'`.
 *
 * ## Usage
 *
 * ```php
 * $pat = new PersonalAccessToken($pdo);
 *
 * // Create
 * ['rawToken' => $raw, 'id' => $id] = $pat->create('user:42', 'CI deploy', ['deploy']);
 *
 * // Authenticate (updates last_used_at)
 * $token = $pat->authenticate($raw, 'deploy');
 * if ($token === null) { http_response_code(401); exit; }
 *
 * // List
 * $pat->list('user:42');
 *
 * // Revoke
 * $pat->revoke($id, 'user:42');
 * ```
 */
final class PersonalAccessToken
{
    private const TABLE  = 'personal_access_tokens';
    private const PREFIX = 'pat';

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Create a new Personal Access Token.
     *
     * @param  string      $userId     Application-level user identifier.
     * @param  string      $name       Human-readable label (e.g. "GitHub Actions").
     * @param  list<string>|string $abilities Array of abilities, or '*' for all.
     * @param  int|null    $expiresIn  Seconds until expiry. Null = no expiry.
     * @return array{rawToken: string, id: int}
     */
    public function create(
        string $userId,
        string $name = '',
        array|string $abilities = '*',
        ?int $expiresIn = null,
    ): array {
        $rawToken  = $this->generate();
        $prefix    = substr($rawToken, 0, 16);
        $tokenHash = $this->hash($rawToken);
        $encoded   = is_array($abilities) ? json_encode($abilities, JSON_THROW_ON_ERROR) : '*';
        $expiresAt = $expiresIn !== null
            ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . $expiresIn . ' seconds')
                ->format('Y-m-d H:i:s')
            : null;

        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (prefix, token_hash, user_id, name, abilities, expires_at)' .
            ' VALUES (:prefix, :hash, :user, :name, :abilities, :exp)'
        );
        $stmt->bindValue(':prefix', $prefix, PDO::PARAM_STR);
        $stmt->bindValue(':hash', $tokenHash, PDO::PARAM_STR);
        $stmt->bindValue(':user', $userId, PDO::PARAM_STR);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':abilities', $encoded, PDO::PARAM_STR);
        $stmt->bindValue(':exp', $expiresAt, $expiresAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();

        return ['rawToken' => $rawToken, 'id' => (int)$db->lastInsertId()];
    }

    /**
     * Authenticate a raw token and check the required ability.
     *
     * On success, records last_used_at and returns the token record (without token_hash).
     * Returns null for any failure (invalid, expired, revoked, insufficient ability).
     *
     * @param  string $rawToken        Raw token provided by the client.
     * @param  string $requiredAbility Ability to check. '*' always passes.
     * @return array{id:int,user_id:string,name:string,abilities:string,last_used_at:string|null,expires_at:string|null,created_at:string}|null
     */
    public function authenticate(string $rawToken, string $requiredAbility = '*'): ?array
    {
        if ($rawToken === '') {
            return null;
        }

        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, prefix, token_hash, user_id, name, abilities, expires_at, revoked_at, last_used_at, created_at' .
            ' FROM ' . $this->table .
            ' WHERE prefix = :p AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->bindValue(':p', substr($rawToken, 0, 16), PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        if (!hash_equals((string)$row['token_hash'], $this->hash($rawToken))) {
            return null;
        }

        // Expiry check
        if ($row['expires_at'] !== null) {
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            if ((string)$row['expires_at'] <= $now) {
                return null;
            }
        }

        // Ability check
        if (!$this->hasAbility((string)$row['abilities'], $requiredAbility)) {
            return null;
        }

        // Record last used
        $lastUsedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $upd        = $db->prepare(
            'UPDATE ' . $this->table . ' SET last_used_at = :t WHERE id = :id'
        );
        $upd->bindValue(':t', $lastUsedAt, PDO::PARAM_STR);
        $upd->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
        $upd->execute();

        return [
            'id'           => (int)$row['id'],
            'user_id'      => (string)$row['user_id'],
            'name'         => (string)$row['name'],
            'abilities'    => (string)$row['abilities'],
            'last_used_at' => $lastUsedAt,
            'expires_at'   => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
            'created_at'   => (string)$row['created_at'],
        ];
    }

    /**
     * List active (non-revoked) tokens for a user. token_hash is never returned.
     *
     * @param  string $userId Application-level user identifier.
     * @return list<array{id:int,name:string,abilities:string,last_used_at:string|null,expires_at:string|null,created_at:string}>
     */
    public function list(string $userId): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, name, abilities, last_used_at, expires_at, created_at' .
            ' FROM ' . $this->table .
            ' WHERE user_id = :u AND revoked_at IS NULL' .
            ' ORDER BY id ASC'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'id'           => (int)$r['id'],
            'name'         => (string)$r['name'],
            'abilities'    => (string)$r['abilities'],
            'last_used_at' => $r['last_used_at'] !== null ? (string)$r['last_used_at'] : null,
            'expires_at'   => $r['expires_at'] !== null ? (string)$r['expires_at'] : null,
            'created_at'   => (string)$r['created_at'],
        ], $rows);
    }

    /**
     * Revoke a token. Only the owning user can revoke their own token.
     *
     * Returns true if revoked, false if not found or wrong owner.
     *
     * @param int    $tokenId Row ID returned by create().
     * @param string $userId  Must match the user_id used in create().
     */
    public function revoke(int $tokenId, string $userId): bool
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $revokedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET revoked_at = :t WHERE id = :id AND user_id = :u AND revoked_at IS NULL'
        );
        $stmt->bindValue(':t', $revokedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $tokenId, PDO::PARAM_INT);
        $stmt->bindValue(':u', $userId, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function generate(): string
    {
        return self::PREFIX . '_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Check whether the stored abilities JSON (or '*') includes $requiredAbility.
     */
    private function hasAbility(string $stored, string $requiredAbility): bool
    {
        if ($stored === '*' || $requiredAbility === '*') {
            return true;
        }
        $abilities = json_decode($stored, true);
        if (!is_array($abilities)) {
            return false;
        }
        return in_array('*', $abilities, true) || in_array($requiredAbility, $abilities, true);
    }
}
