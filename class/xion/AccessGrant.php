<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * AccessGrant — time-bounded access delegation between entities.
 *
 * Allows one entity (the granter) to temporarily delegate access to a resource
 * to another entity (the grantee) with a specific permission set and an
 * optional expiry. Useful for shared folder access, document collaboration
 * invites, or any scenario where access needs to be revocable and time-limited.
 *
 * Distinct from RBAC (role-based) and ACL (static permission lists); this is
 * delegated grant with an audit trail and expiry.
 *
 * ## Usage
 *
 * ```php
 * $ag = new AccessGrant($pdo);
 *
 * $id = $ag->grant('user-1', 'user-2', 'document', 'doc-42', ['read', 'comment'], '2026-12-31 23:59:59');
 *
 * $ag->hasAccess('user-2', 'document', 'doc-42', 'read');    // true
 * $ag->hasAccess('user-2', 'document', 'doc-42', 'write');   // false
 *
 * $ag->revoke($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE access_grants (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     granter_id    VARCHAR(255) NOT NULL,
 *     grantee_id    VARCHAR(255) NOT NULL,
 *     resource_type VARCHAR(100) NOT NULL,
 *     resource_id   VARCHAR(255) NOT NULL,
 *     permissions   TEXT         NOT NULL,
 *     status        VARCHAR(20)  NOT NULL DEFAULT 'active',
 *     expires_at    DATETIME     NULL,
 *     granted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class AccessGrant
{
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_REVOKED = 'revoked';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Grant access to a resource.
     *
     * @param list<string>  $permissions List of permission strings (e.g. ['read', 'comment']).
     * @param string|null   $expiresAt   Optional expiry datetime ('Y-m-d H:i:s').
     * @return int New grant ID.
     * @throws \InvalidArgumentException on empty IDs/types/permissions.
     */
    public function grant(
        string $granterId,
        string $granteeId,
        string $resourceType,
        string $resourceId,
        array $permissions,
        ?string $expiresAt = null
    ): int {
        $granterId    = trim($granterId);
        $granteeId    = trim($granteeId);
        $resourceType = trim($resourceType);
        $resourceId   = trim($resourceId);
        if ($granterId === '') {
            throw new \InvalidArgumentException('granter_id must not be empty.');
        }
        if ($granteeId === '') {
            throw new \InvalidArgumentException('grantee_id must not be empty.');
        }
        if ($resourceType === '') {
            throw new \InvalidArgumentException('resource_type must not be empty.');
        }
        if ($resourceId === '') {
            throw new \InvalidArgumentException('resource_id must not be empty.');
        }
        if (empty($permissions)) {
            throw new \InvalidArgumentException('permissions must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO access_grants (granter_id, grantee_id, resource_type, resource_id, permissions, status, expires_at, granted_at)
             VALUES (:granter, :grantee, :rtype, :rid, :perms, :status, :exp, :now)'
        );
        $stmt->execute([
            ':granter' => $granterId,
            ':grantee' => $granteeId,
            ':rtype'   => $resourceType,
            ':rid'     => $resourceId,
            ':perms'   => json_encode(array_values($permissions), JSON_UNESCAPED_UNICODE),
            ':status'  => self::STATUS_ACTIVE,
            ':exp'     => $expiresAt,
            ':now'     => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a grant row by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM access_grants WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Check whether a grantee has a specific permission on a resource.
     *
     * Returns false if the grant is revoked or expired.
     */
    public function hasAccess(
        string $granteeId,
        string $resourceType,
        string $resourceId,
        string $permission
    ): bool {
        $grants = $this->activeGrantsFor($granteeId, $resourceType, $resourceId);
        foreach ($grants as $grant) {
            $perms = json_decode((string)$grant['permissions'], true);
            if (is_array($perms) && in_array($permission, $perms, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Revoke a grant.
     *
     * @return bool True if found and revoked.
     */
    public function revoke(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE access_grants SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_REVOKED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke all active grants for a grantee on a resource.
     *
     * @return int Number of grants revoked.
     */
    public function revokeAll(string $granteeId, string $resourceType, string $resourceId): int
    {
        $stmt = $this->db()->prepare(
            'UPDATE access_grants SET status = :status
             WHERE grantee_id = :grantee AND resource_type = :rtype AND resource_id = :rid
               AND status = :active'
        );
        $stmt->execute([
            ':status'  => self::STATUS_REVOKED,
            ':grantee' => trim($granteeId),
            ':rtype'   => trim($resourceType),
            ':rid'     => trim($resourceId),
            ':active'  => self::STATUS_ACTIVE,
        ]);
        return $stmt->rowCount();
    }

    /**
     * Return grants that a grantee has been given (what they can access).
     *
     * @return list<array<string,mixed>>
     */
    public function myGrants(string $granteeId): array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT * FROM access_grants
             WHERE grantee_id = :grantee AND status = :active
               AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY granted_at DESC'
        );
        $stmt->execute([':grantee' => trim($granteeId), ':active' => self::STATUS_ACTIVE, ':now' => $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return grants that a granter has issued.
     *
     * @return list<array<string,mixed>>
     */
    public function grantsIGave(string $granterId): array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT * FROM access_grants
             WHERE granter_id = :granter AND status = :active
               AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY granted_at DESC'
        );
        $stmt->execute([':granter' => trim($granterId), ':active' => self::STATUS_ACTIVE, ':now' => $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete expired and revoked grants older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purge(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM access_grants
             WHERE (status = :revoked OR (expires_at IS NOT NULL AND expires_at < :cutoff))
               AND granted_at < :cutoff'
        );
        $stmt->execute([':revoked' => self::STATUS_REVOKED, ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function activeGrantsFor(string $granteeId, string $resourceType, string $resourceId): array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT * FROM access_grants
             WHERE grantee_id = :grantee AND resource_type = :rtype AND resource_id = :rid
               AND status = :active
               AND (expires_at IS NULL OR expires_at > :now)'
        );
        $stmt->execute([
            ':grantee' => trim($granteeId),
            ':rtype'   => trim($resourceType),
            ':rid'     => trim($resourceId),
            ':active'  => self::STATUS_ACTIVE,
            ':now'     => $now,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
