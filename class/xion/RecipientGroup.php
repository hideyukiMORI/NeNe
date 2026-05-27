<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * RecipientGroup — mailing list / recipient group management.
 *
 * Manages named groups of recipients for bulk messaging (email campaigns,
 * push notifications, announcements). Two-table design: group definitions
 * plus member assignments. Members are identified by a user/contact ID and
 * optionally an email address.
 *
 * Distinct from NewsletterSubscription (user-facing double opt-in per list);
 * RecipientGroup is admin-managed for internal targeting and campaigns.
 *
 * ## Usage
 *
 * ```php
 * $rg = new RecipientGroup($pdo);
 *
 * $id = $rg->create('beta-testers', 'Internal beta program');
 * $rg->addMember($id, 'user-1', 'alice@example.com');
 * $rg->addMember($id, 'user-2', 'bob@example.com');
 *
 * $members = $rg->members($id);       // [['user_id' => ..., 'email' => ...], ...]
 * $count   = $rg->count($id);         // 2
 * $groups  = $rg->groupsFor('user-1'); // groups user-1 belongs to
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE recipient_groups (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     slug        VARCHAR(100) NOT NULL UNIQUE,
 *     name        VARCHAR(255) NOT NULL,
 *     description TEXT         NULL,
 *     active      TINYINT(1)   NOT NULL DEFAULT 1,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE recipient_group_members (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     group_id   INTEGER      NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL,
 *     email      VARCHAR(255) NULL,
 *     added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (group_id, user_id)
 * );
 * ```
 */
final class RecipientGroup
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a new recipient group.
     *
     * @return int New group ID.
     * @throws \InvalidArgumentException on empty slug/name.
     * @throws \RuntimeException if slug already exists.
     */
    public function create(string $slug, ?string $name = null, ?string $description = null): int
    {
        $slug = trim($slug);
        if ($slug === '') {
            throw new \InvalidArgumentException('slug must not be empty.');
        }
        if ($name === null || trim($name) === '') {
            $name = $slug;
        }

        // Check duplicate slug
        $existing = $this->findBySlug($slug);
        if ($existing !== null) {
            throw new \RuntimeException("Group slug '{$slug}' already exists.");
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO recipient_groups (slug, name, description, active, created_at)
             VALUES (:slug, :name, :desc, 1, :now)'
        );
        $stmt->execute([':slug' => $slug, ':name' => trim($name), ':desc' => $description, ':now' => $now]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a group by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM recipient_groups WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Retrieve a group by slug.
     *
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM recipient_groups WHERE slug = :slug');
        $stmt->execute([':slug' => trim($slug)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return all active groups.
     *
     * @return list<array<string,mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->db()->query('SELECT * FROM recipient_groups WHERE active = 1 ORDER BY slug ASC');
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Add a member to a group. Idempotent — updates email if already a member.
     *
     * @return bool True if newly added; false if already a member (upsert).
     * @throws \InvalidArgumentException on empty userId.
     */
    public function addMember(int $groupId, string $userId, ?string $email = null): bool
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = 'INSERT INTO recipient_group_members (group_id, user_id, email, added_at)
                    VALUES (:gid, :uid, :email, :now)
                    ON CONFLICT (group_id, user_id) DO UPDATE SET email = :email2';
            $this->db()->prepare($sql)->execute([
                ':gid'    => $groupId,
                ':uid'    => $userId,
                ':email'  => $email,
                ':now'    => $now,
                ':email2' => $email,
            ]);
        } else {
            $sql = 'INSERT INTO recipient_group_members (group_id, user_id, email, added_at)
                    VALUES (:gid, :uid, :email, :now)
                    ON DUPLICATE KEY UPDATE email = :email2';
            $this->db()->prepare($sql)->execute([
                ':gid'    => $groupId,
                ':uid'    => $userId,
                ':email'  => $email,
                ':now'    => $now,
                ':email2' => $email,
            ]);
        }
        return true;
    }

    /**
     * Remove a member from a group.
     *
     * @return bool True if found and removed.
     */
    public function removeMember(int $groupId, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM recipient_group_members WHERE group_id = :gid AND user_id = :uid'
        );
        $stmt->execute([':gid' => $groupId, ':uid' => trim($userId)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return all members of a group.
     *
     * @return list<array<string,mixed>>
     */
    public function members(int $groupId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, group_id, user_id, email, added_at
             FROM recipient_group_members
             WHERE group_id = :gid
             ORDER BY added_at ASC'
        );
        $stmt->execute([':gid' => $groupId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return member count for a group.
     */
    public function count(int $groupId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) AS cnt FROM recipient_group_members WHERE group_id = :gid'
        );
        $stmt->execute([':gid' => $groupId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row !== false ? $row['cnt'] : 0);
    }

    /**
     * Check whether a user is a member of a group.
     */
    public function isMember(int $groupId, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM recipient_group_members WHERE group_id = :gid AND user_id = :uid'
        );
        $stmt->execute([':gid' => $groupId, ':uid' => trim($userId)]);
        return $stmt->fetch() !== false;
    }

    /**
     * Return groups a user belongs to.
     *
     * @return list<array<string,mixed>>
     */
    public function groupsFor(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT g.id, g.slug, g.name, g.active
             FROM recipient_groups g
             INNER JOIN recipient_group_members m ON g.id = m.group_id
             WHERE m.user_id = :uid
             ORDER BY g.slug ASC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete a group and all its members.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $db = $this->db();
        $db->prepare('DELETE FROM recipient_group_members WHERE group_id = :id')->execute([':id' => $id]);
        $stmt = $db->prepare('DELETE FROM recipient_groups WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
