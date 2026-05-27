<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * API key management: generation, hashed storage, scope-based auth, revocation, rotation.
 *
 * Key format: `nk_{base64url(32 random bytes)}`  — 256-bit entropy, type-prefixed.
 * Storage: only the SHA-256 hash is stored. The raw key is returned once at creation.
 * Lookup: a 16-character prefix enables O(1) indexed lookup before hash comparison.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE api_keys (
 *     id         INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     prefix     VARCHAR(16)  NOT NULL,           -- first 16 chars of raw key (indexed)
 *     key_hash   VARCHAR(64)  NOT NULL UNIQUE,    -- SHA-256 of raw key
 *     owner_id   VARCHAR(255) NOT NULL,
 *     scope      VARCHAR(32)  NOT NULL DEFAULT 'read',  -- 'read', 'write', or 'admin'
 *     label      VARCHAR(255) NOT NULL DEFAULT '',
 *     expires_at DATETIME     DEFAULT NULL,       -- NULL = no expiry
 *     revoked_at DATETIME     DEFAULT NULL,       -- NULL = active
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_api_keys_prefix ON api_keys (prefix);
 * ```
 *
 * ## Scope hierarchy
 *
 * `admin` ⊃ `write` ⊃ `read`
 *
 * An `admin` key satisfies any scope requirement. A `read` key is rejected by
 * endpoints that require `write` or `admin`.
 *
 * ## Usage
 *
 * ```php
 * $manager = new ApiKey();
 *
 * // Create
 * ['rawKey' => $raw, 'id' => $id] = $manager->create('user:42', scope: 'write', label: 'CI key');
 * // Send $raw to the client once. Never store it.
 *
 * // Authenticate
 * $key = $manager->authenticate($raw, requiredScope: 'write');
 * if ($key === null) {
 *     http_response_code(401); exit;
 * }
 *
 * // Revoke
 * $manager->revoke($id, ownerId: 'user:42');
 *
 * // Rotate (create-first, revoke-after — no lockout risk)
 * ['rawKey' => $newRaw] = $manager->rotate($id, ownerId: 'user:42');
 * ```
 */
final class ApiKey
{
    private const TABLE  = 'api_keys';
    private const PREFIX = 'nk';

    /** Scope hierarchy: higher index = broader access */
    private const SCOPE_LEVELS = ['read' => 0, 'write' => 1, 'admin' => 2];

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Create a new API key.
     *
     * @param  string      $ownerId   Application-level owner identifier.
     * @param  string      $scope     'read', 'write', or 'admin'.
     * @param  string      $label     Human-readable label (e.g. "GitHub Actions").
     * @param  int|null    $expiresIn Seconds until expiry. Null = no expiry.
     * @return array{rawKey: string, id: int} Raw key (show once) and new row ID.
     */
    public function create(
        string $ownerId,
        string $scope = 'read',
        string $label = '',
        ?int $expiresIn = null,
    ): array {
        $this->assertScope($scope);

        $rawKey    = $this->generate();
        $prefix    = $this->extractPrefix($rawKey);
        $keyHash   = $this->hash($rawKey);
        $expiresAt = $expiresIn !== null
            ? (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . $expiresIn . ' seconds')
                ->format('Y-m-d H:i:s')
            : null;

        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (prefix, key_hash, owner_id, scope, label, expires_at)' .
            ' VALUES (:prefix, :hash, :owner, :scope, :label, :exp)'
        );
        $stmt->bindValue(':prefix', $prefix, PDO::PARAM_STR);
        $stmt->bindValue(':hash', $keyHash, PDO::PARAM_STR);
        $stmt->bindValue(':owner', $ownerId, PDO::PARAM_STR);
        $stmt->bindValue(':scope', $scope, PDO::PARAM_STR);
        $stmt->bindValue(':label', $label, PDO::PARAM_STR);
        $stmt->bindValue(':exp', $expiresAt, $expiresAt !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();

        return ['rawKey' => $rawKey, 'id' => (int)$db->lastInsertId()];
    }

    /**
     * Authenticate a raw API key and check its scope.
     *
     * Returns the key record on success, null on any failure (missing, invalid,
     * expired, revoked, or insufficient scope). All failures produce the same
     * null result to prevent oracle attacks.
     *
     * @param  string $rawKey        Raw key provided by the client.
     * @param  string $requiredScope Minimum required scope.
     * @return array{id:int,owner_id:string,scope:string,label:string,expires_at:string|null,created_at:string}|null
     */
    public function authenticate(string $rawKey, string $requiredScope = 'read'): ?array
    {
        if ($rawKey === '') {
            return null;
        }

        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, prefix, key_hash, owner_id, scope, label, expires_at, revoked_at, created_at' .
            ' FROM ' . $this->table .
            ' WHERE prefix = :p AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->bindValue(':p', $this->extractPrefix($rawKey), PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        // Timing-safe comparison
        if (!hash_equals((string)$row['key_hash'], $this->hash($rawKey))) {
            return null;
        }

        // Expiry check
        if ($row['expires_at'] !== null) {
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            if ((string)$row['expires_at'] <= $now) {
                return null;
            }
        }

        // Scope check
        if (!$this->scopeAllows((string)$row['scope'], $requiredScope)) {
            return null;
        }

        return [
            'id'         => (int)$row['id'],
            'owner_id'   => (string)$row['owner_id'],
            'scope'      => (string)$row['scope'],
            'label'      => (string)$row['label'],
            'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    /**
     * List active (non-revoked) keys for an owner. key_hash is never returned.
     *
     * @param  string $ownerId Application-level owner identifier.
     * @return list<array{id:int,scope:string,label:string,expires_at:string|null,created_at:string}>
     */
    public function list(string $ownerId): array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, scope, label, expires_at, created_at' .
            ' FROM ' . $this->table .
            ' WHERE owner_id = :o AND revoked_at IS NULL' .
            ' ORDER BY id ASC'
        );
        $stmt->bindValue(':o', $ownerId, PDO::PARAM_STR);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $r) => [
            'id'         => (int)$r['id'],
            'scope'      => (string)$r['scope'],
            'label'      => (string)$r['label'],
            'expires_at' => $r['expires_at'] !== null ? (string)$r['expires_at'] : null,
            'created_at' => (string)$r['created_at'],
        ], $rows);
    }

    /**
     * Revoke a key. Only the owner can revoke their own key.
     *
     * Returns true if revoked, false if not found or wrong owner.
     *
     * @param int    $keyId   Row ID returned by create().
     * @param string $ownerId Must match the owner_id used in create().
     */
    public function revoke(int $keyId, string $ownerId): bool
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $revokedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET revoked_at = :t WHERE id = :id AND owner_id = :o AND revoked_at IS NULL'
        );
        $stmt->bindValue(':t', $revokedAt, PDO::PARAM_STR);
        $stmt->bindValue(':id', $keyId, PDO::PARAM_INT);
        $stmt->bindValue(':o', $ownerId, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /**
     * Rotate a key: create new first, then revoke old (no lockout risk).
     *
     * Returns the new raw key, or null if the old key is not found or wrong owner.
     *
     * @param int    $oldKeyId Row ID of the key to replace.
     * @param string $ownerId  Must match the owner_id of the old key.
     * @return array{rawKey: string, id: int}|null
     */
    public function rotate(int $oldKeyId, string $ownerId): ?array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT scope, label, expires_at FROM ' . $this->table .
            ' WHERE id = :id AND owner_id = :o AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->bindValue(':id', $oldKeyId, PDO::PARAM_INT);
        $stmt->bindValue(':o', $ownerId, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        // Create-first to avoid lockout if revoke fails
        $newKey = $this->create($ownerId, (string)$row['scope'], (string)$row['label']);
        $this->revoke($oldKeyId, $ownerId);

        return $newKey;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    /**
     * Generate a new raw API key: `nk_{base64url(32 random bytes)}`.
     */
    private function generate(): string
    {
        $raw = random_bytes(32);
        return self::PREFIX . '_' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Extract the first 16 characters for DB-level prefix lookup.
     * Includes the type prefix + 13 chars of random material (≈78 bits differentiation).
     */
    private function extractPrefix(string $rawKey): string
    {
        return substr($rawKey, 0, 16);
    }

    /**
     * SHA-256 hash of the raw key for safe DB storage.
     */
    public function hash(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * Check whether $actualScope satisfies $requiredScope in the hierarchy.
     */
    private function scopeAllows(string $actualScope, string $requiredScope): bool
    {
        $levels = self::SCOPE_LEVELS;
        return isset($levels[$actualScope], $levels[$requiredScope])
            && $levels[$actualScope] >= $levels[$requiredScope];
    }

    private function assertScope(string $scope): void
    {
        if (!isset(self::SCOPE_LEVELS[$scope])) {
            throw new \InvalidArgumentException(
                "Invalid scope '{$scope}'. Allowed: read, write, admin."
            );
        }
    }
}
