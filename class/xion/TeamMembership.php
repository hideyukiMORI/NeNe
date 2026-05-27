<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * TeamMembership — organization/team membership with roles.
 *
 * Manages team composition: add/remove members, change roles, and query
 * membership state. Each user can be in multiple teams; each team member
 * has a single role string. Use in tandem with a teams table or as a
 * standalone membership store.
 *
 * ## Usage
 *
 * ```php
 * $tm = new TeamMembership($pdo);
 *
 * // Add members
 * $tm->add('team-a', 'user-1', 'owner');
 * $tm->add('team-a', 'user-2', 'member');
 *
 * // Query
 * $members = $tm->members('team-a');
 * $teams   = $tm->teams('user-1');
 * $role    = $tm->role('team-a', 'user-1'); // 'owner'
 *
 * // Update role
 * $tm->changeRole('team-a', 'user-2', 'admin');
 *
 * // Remove
 * $tm->remove('team-a', 'user-2');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE team_members (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     team_id     VARCHAR(255) NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     role        VARCHAR(50)  NOT NULL DEFAULT 'member',
 *     invited_by  VARCHAR(255) NOT NULL DEFAULT '',
 *     joined_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (team_id, user_id)
 * );
 * ```
 */
final class TeamMembership
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a user to a team. Idempotent — if already a member, updates role.
     *
     * @throws \InvalidArgumentException on empty inputs.
     */
    public function add(string $teamId, string $userId, string $role = 'member', string $invitedBy = ''): void
    {
        [$teamId, $userId] = $this->validateInputs($teamId, $userId);
        $db = $this->db();

        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT INTO team_members (team_id, user_id, role, invited_by)
                 VALUES (:team, :user, :role, :inv)
                 ON CONFLICT (team_id, user_id)
                 DO UPDATE SET role = excluded.role'
            )->execute([':team' => $teamId, ':user' => $userId, ':role' => $role, ':inv' => $invitedBy]);
        } else {
            $db->prepare(
                'INSERT INTO team_members (team_id, user_id, role, invited_by)
                 VALUES (:team, :user, :role, :inv)
                 ON DUPLICATE KEY UPDATE role = VALUES(role)'
            )->execute([':team' => $teamId, ':user' => $userId, ':role' => $role, ':inv' => $invitedBy]);
        }
    }

    /**
     * Remove a user from a team.
     *
     * @return bool True if the membership existed and was removed.
     */
    public function remove(string $teamId, string $userId): bool
    {
        [$teamId, $userId] = $this->validateInputs($teamId, $userId);
        $stmt = $this->db()->prepare(
            'DELETE FROM team_members WHERE team_id = :team AND user_id = :user'
        );
        $stmt->execute([':team' => $teamId, ':user' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Change a member's role.
     *
     * @return bool True if the membership existed and was updated.
     */
    public function changeRole(string $teamId, string $userId, string $role): bool
    {
        [$teamId, $userId] = $this->validateInputs($teamId, $userId);
        $stmt = $this->db()->prepare(
            'UPDATE team_members SET role = :role WHERE team_id = :team AND user_id = :user'
        );
        $stmt->execute([':role' => $role, ':team' => $teamId, ':user' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether a user is a member of a team.
     */
    public function isMember(string $teamId, string $userId): bool
    {
        return $this->role($teamId, $userId) !== null;
    }

    /**
     * Check whether a user has a specific role in a team.
     */
    public function hasRole(string $teamId, string $userId, string $role): bool
    {
        return $this->role($teamId, $userId) === $role;
    }

    /**
     * Get the role of a user in a team (null if not a member).
     */
    public function role(string $teamId, string $userId): ?string
    {
        [$teamId, $userId] = $this->validateInputs($teamId, $userId);
        $stmt = $this->db()->prepare(
            'SELECT role FROM team_members WHERE team_id = :team AND user_id = :user LIMIT 1'
        );
        $stmt->execute([':team' => $teamId, ':user' => $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * List all members of a team.
     *
     * @return list<array<string,mixed>>
     */
    public function members(string $teamId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, team_id, user_id, role, invited_by, joined_at
             FROM team_members WHERE team_id = :team ORDER BY joined_at ASC'
        );
        $stmt->execute([':team' => trim($teamId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all teams a user belongs to.
     *
     * @return list<array<string,mixed>>
     */
    public function teams(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, team_id, user_id, role, invited_by, joined_at
             FROM team_members WHERE user_id = :user ORDER BY joined_at ASC'
        );
        $stmt->execute([':user' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count members in a team.
     */
    public function count(string $teamId): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM team_members WHERE team_id = :team');
        $stmt->execute([':team' => trim($teamId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Remove all members from a team.
     *
     * @return int Number of memberships deleted.
     */
    public function disband(string $teamId): int
    {
        $stmt = $this->db()->prepare('DELETE FROM team_members WHERE team_id = :team');
        $stmt->execute([':team' => trim($teamId)]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function validateInputs(string $teamId, string $userId): array
    {
        $teamId = trim($teamId);
        $userId = trim($userId);
        if ($teamId === '') {
            throw new \InvalidArgumentException('team_id must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$teamId, $userId];
    }
}
