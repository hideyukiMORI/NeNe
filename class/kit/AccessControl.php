<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * AccessControl — per-resource subject ACL (access-control list).
 *
 * Grants named permissions to subjects (users, groups, roles) on
 * specific resources. Permissions are arbitrary strings (e.g. "read",
 * "write", "admin", "comment"). Multiple permissions per
 * subject+resource pair are stored as separate rows.
 *
 * This is complementary to RBAC (RoleGuard, FT31): RBAC provides
 * global role-based access; AccessControl provides per-resource grants.
 *
 * ## Usage
 *
 * ```php
 * $ac = new AccessControl($pdo);
 *
 * // Grant / revoke
 * $ac->grant('doc', '42', 'user-1', 'write');
 * $ac->grant('doc', '42', 'user-2', 'read');
 * $ac->revoke('doc', '42', 'user-2', 'read');
 *
 * // Check
 * $ac->can('doc', '42', 'user-1', 'write');  // true
 * $ac->permissions('doc', '42', 'user-1');   // ['write']
 * $ac->subjects('doc', '42', 'write');       // ['user-1']
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE access_control (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     resource_type VARCHAR(100) NOT NULL,
 *     resource_id   VARCHAR(255) NOT NULL,
 *     subject_id    VARCHAR(255) NOT NULL,
 *     permission    VARCHAR(100) NOT NULL,
 *     granted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (resource_type, resource_id, subject_id, permission)
 * );
 * ```
 */
final class AccessControl
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Grant a permission to a subject on a resource (idempotent).
     *
     * @throws \InvalidArgumentException on empty fields.
     */
    public function grant(
        string $resourceType,
        string $resourceId,
        string $subjectId,
        string $permission
    ): void {
        [$resourceType, $resourceId, $subjectId, $permission] = $this->validateAll(
            $resourceType,
            $resourceId,
            $subjectId,
            $permission
        );

        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $ignore = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

        $this->db()->prepare(
            "{$ignore} INTO access_control (resource_type, resource_id, subject_id, permission)
             VALUES (:rtype, :rid, :sub, :perm)"
        )->execute([
            ':rtype' => $resourceType,
            ':rid'   => $resourceId,
            ':sub'   => $subjectId,
            ':perm'  => $permission,
        ]);
    }

    /**
     * Revoke a specific permission from a subject on a resource.
     *
     * @return bool True if a row was deleted.
     */
    public function revoke(
        string $resourceType,
        string $resourceId,
        string $subjectId,
        string $permission
    ): bool {
        $stmt = $this->db()->prepare(
            'DELETE FROM access_control
             WHERE resource_type = :rtype AND resource_id = :rid
               AND subject_id = :sub AND permission = :perm'
        );
        $stmt->execute([
            ':rtype' => trim($resourceType),
            ':rid'   => trim($resourceId),
            ':sub'   => trim($subjectId),
            ':perm'  => trim($permission),
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Revoke all permissions from a subject on a resource.
     *
     * @return int Number of rows deleted.
     */
    public function revokeAll(string $resourceType, string $resourceId, string $subjectId): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM access_control
             WHERE resource_type = :rtype AND resource_id = :rid AND subject_id = :sub'
        );
        $stmt->execute([
            ':rtype' => trim($resourceType),
            ':rid'   => trim($resourceId),
            ':sub'   => trim($subjectId),
        ]);
        return $stmt->rowCount();
    }

    /**
     * Check whether a subject has a specific permission on a resource.
     */
    public function can(
        string $resourceType,
        string $resourceId,
        string $subjectId,
        string $permission
    ): bool {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM access_control
             WHERE resource_type = :rtype AND resource_id = :rid
               AND subject_id = :sub AND permission = :perm'
        );
        $stmt->execute([
            ':rtype' => trim($resourceType),
            ':rid'   => trim($resourceId),
            ':sub'   => trim($subjectId),
            ':perm'  => trim($permission),
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Return all permissions a subject has on a resource.
     *
     * @return list<string>
     */
    public function permissions(string $resourceType, string $resourceId, string $subjectId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT permission FROM access_control
             WHERE resource_type = :rtype AND resource_id = :rid AND subject_id = :sub
             ORDER BY permission ASC'
        );
        $stmt->execute([
            ':rtype' => trim($resourceType),
            ':rid'   => trim($resourceId),
            ':sub'   => trim($subjectId),
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Return all subjects that have a specific permission on a resource.
     *
     * @return list<string>
     */
    public function subjects(string $resourceType, string $resourceId, string $permission): array
    {
        $stmt = $this->db()->prepare(
            'SELECT subject_id FROM access_control
             WHERE resource_type = :rtype AND resource_id = :rid AND permission = :perm
             ORDER BY subject_id ASC'
        );
        $stmt->execute([
            ':rtype' => trim($resourceType),
            ':rid'   => trim($resourceId),
            ':perm'  => trim($permission),
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Return the full ACL for a resource (all subjects and permissions).
     *
     * @return list<array<string,mixed>>
     */
    public function aclFor(string $resourceType, string $resourceId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, resource_type, resource_id, subject_id, permission, granted_at
             FROM access_control
             WHERE resource_type = :rtype AND resource_id = :rid
             ORDER BY subject_id ASC, permission ASC'
        );
        $stmt->execute([':rtype' => trim($resourceType), ':rid' => trim($resourceId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Remove all ACL entries for a resource (e.g. when the resource is deleted).
     *
     * @return int Number of rows deleted.
     */
    public function clearResource(string $resourceType, string $resourceId): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM access_control WHERE resource_type = :rtype AND resource_id = :rid'
        );
        $stmt->execute([':rtype' => trim($resourceType), ':rid' => trim($resourceId)]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string, string, string}
     */
    private function validateAll(
        string $resourceType,
        string $resourceId,
        string $subjectId,
        string $permission
    ): array {
        $resourceType = trim($resourceType);
        $resourceId   = trim($resourceId);
        $subjectId    = trim($subjectId);
        $permission   = trim($permission);
        if ($resourceType === '') {
            throw new \InvalidArgumentException('resource_type must not be empty.');
        }
        if ($resourceId === '') {
            throw new \InvalidArgumentException('resource_id must not be empty.');
        }
        if ($subjectId === '') {
            throw new \InvalidArgumentException('subject_id must not be empty.');
        }
        if ($permission === '') {
            throw new \InvalidArgumentException('permission must not be empty.');
        }
        return [$resourceType, $resourceId, $subjectId, $permission];
    }
}
