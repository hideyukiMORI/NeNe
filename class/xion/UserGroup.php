<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * UserGroup — user group and team membership management.
 *
 * Groups are named collections of users with optional per-member roles.
 * Useful for teams, organisations, permission groups, and any scenario
 * requiring membership-based access control.
 *
 * ## Usage
 *
 * ```php
 * $ug = new UserGroup($pdo);
 *
 * // Create a group
 * $id = $ug->create('engineers', 'Engineering team');
 *
 * // Add members
 * $ug->addMember($id, 'user-1', 'admin');
 * $ug->addMember($id, 'user-2');  // default role: 'member'
 *
 * // Query membership
 * $ug->isMember($id, 'user-1');            // true
 * $ug->getRole($id, 'user-1');             // 'admin'
 * $ug->listMembers($id);                   // [{user_id, role, joined_at}, ...]
 * $ug->listGroups('user-1');               // [{id, name, role, joined_at}, ...]
 *
 * // Manage
 * $ug->setRole($id, 'user-2', 'admin');
 * $ug->removeMember($id, 'user-2');
 * $ug->delete($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_groups (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name        VARCHAR(100) NOT NULL UNIQUE,
 *     description TEXT         NOT NULL DEFAULT '',
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE user_group_members (
 *     group_id   INTEGER      NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL,
 *     role       VARCHAR(50)  NOT NULL DEFAULT 'member',
 *     joined_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (group_id, user_id)
 * );
 * ```
 */
final class UserGroup
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new group.
     *
     * @return int The new group ID.
     * @throws \InvalidArgumentException if name is empty.
     */
    public function create(string $name, string $description = ''): int
    {
        $name = $this->validateName($name);
        $db   = $this->db();
        $db->prepare(
            'INSERT INTO user_groups (name, description) VALUES (:name, :desc)'
        )->execute([':name' => $name, ':desc' => $description]);
        return (int)$db->lastInsertId();
    }

    /**
     * Delete a group and all its memberships.
     *
     * @return bool True if the group existed.
     */
    public function delete(int $groupId): bool
    {
        $db = $this->db();
        $db->prepare('DELETE FROM user_group_members WHERE group_id = :gid')->execute([':gid' => $groupId]);
        $stmt = $db->prepare('DELETE FROM user_groups WHERE id = :gid');
        $stmt->execute([':gid' => $groupId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Find a group by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $groupId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, name, description, created_at FROM user_groups WHERE id = :gid LIMIT 1'
        );
        $stmt->execute([':gid' => $groupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a group by name.
     *
     * @return array<string,mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $name = trim($name);
        $stmt = $this->db()->prepare(
            'SELECT id, name, description, created_at FROM user_groups WHERE name = :name LIMIT 1'
        );
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Add a user to a group (idempotent; updates role if already a member).
     *
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function addMember(int $groupId, string $userId, string $role = 'member'): void
    {
        $userId = $this->validateUserId($userId);
        $db     = $this->db();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO user_group_members (group_id, user_id, role)
                 VALUES (:gid, :uid, :role)
                 ON CONFLICT (group_id, user_id)
                 DO UPDATE SET role = excluded.role, joined_at = CURRENT_TIMESTAMP'
            )->execute([':gid' => $groupId, ':uid' => $userId, ':role' => $role]);
        } else {
            $db->prepare(
                'INSERT INTO user_group_members (group_id, user_id, role)
                 VALUES (:gid, :uid, :role)
                 ON DUPLICATE KEY UPDATE role = VALUES(role), joined_at = CURRENT_TIMESTAMP'
            )->execute([':gid' => $groupId, ':uid' => $userId, ':role' => $role]);
        }
    }

    /**
     * Remove a user from a group.
     *
     * @return bool True if the user was a member.
     */
    public function removeMember(int $groupId, string $userId): bool
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'DELETE FROM user_group_members WHERE group_id = :gid AND user_id = :uid'
        );
        $stmt->execute([':gid' => $groupId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether a user is a member of a group.
     */
    public function isMember(int $groupId, string $userId): bool
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM user_group_members WHERE group_id = :gid AND user_id = :uid'
        );
        $stmt->execute([':gid' => $groupId, ':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get a member's role within a group.
     *
     * @return string|null null if the user is not a member.
     */
    public function getRole(int $groupId, string $userId): ?string
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT role FROM user_group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':gid' => $groupId, ':uid' => $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Update a member's role.
     *
     * @return bool True if the user was a member and the role was updated.
     */
    public function setRole(int $groupId, string $userId, string $role): bool
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'UPDATE user_group_members SET role = :role WHERE group_id = :gid AND user_id = :uid'
        );
        $stmt->execute([':role' => $role, ':gid' => $groupId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all members of a group.
     *
     * @return list<array{user_id: string, role: string, joined_at: string}>
     */
    public function listMembers(int $groupId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT user_id, role, joined_at FROM user_group_members
             WHERE group_id = :gid ORDER BY joined_at ASC'
        );
        $stmt->execute([':gid' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all groups a user belongs to.
     *
     * @return list<array{id: int, name: string, description: string, role: string, joined_at: string}>
     */
    public function listGroups(string $userId): array
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT g.id, g.name, g.description, m.role, m.joined_at
             FROM user_groups g
             JOIN user_group_members m ON m.group_id = g.id
             WHERE m.user_id = :uid
             ORDER BY m.joined_at ASC'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count total groups.
     */
    public function count(): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM user_groups');
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count members in a group.
     */
    public function memberCount(int $groupId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM user_group_members WHERE group_id = :gid'
        );
        $stmt->execute([':gid' => $groupId]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('group name must not be empty.');
        }
        return $name;
    }

    private function validateUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }
}
