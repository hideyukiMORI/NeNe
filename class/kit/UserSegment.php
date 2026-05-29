<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * UserSegment — user cohort/segment assignment for targeting and analytics.
 *
 * Assigns users to named segments (e.g. "power-users", "trial-expired",
 * "beta-testers"). Segments can have an optional description. The
 * membership table uses a UNIQUE constraint so each user is in each
 * segment at most once.
 *
 * Use cases: A/B test cohorts, feature rollout groups, marketing targeting,
 * retention cohorts, risk classification.
 *
 * ## Usage
 *
 * ```php
 * $seg = new UserSegment($pdo);
 *
 * $seg->createSegment('beta-testers', 'Users enrolled in the beta program');
 * $seg->addUser('beta-testers', 'user-1');
 * $seg->addUser('beta-testers', 'user-2');
 *
 * $users = $seg->usersIn('beta-testers');
 * $segs  = $seg->segmentsFor('user-1');
 * $seg->removeUser('beta-testers', 'user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_segments (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name        VARCHAR(100) NOT NULL UNIQUE,
 *     description TEXT         NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE user_segment_members (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     segment    VARCHAR(100) NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL,
 *     added_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (segment, user_id)
 * );
 * ```
 */
final class UserSegment
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── segment management ────────────────────────────────────────────────────

    /**
     * Create a new segment. Idempotent (duplicate names are ignored).
     *
     * @throws \InvalidArgumentException on empty name.
     */
    public function createSegment(string $name, ?string $description = null): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('segment name must not be empty.');
        }
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = 'INSERT OR IGNORE INTO user_segments (name, description, created_at)
                    VALUES (:name, :desc, :now)';
        } else {
            $sql = 'INSERT IGNORE INTO user_segments (name, description, created_at)
                    VALUES (:name, :desc, :now)';
        }
        $this->db()->prepare($sql)->execute([':name' => $name, ':desc' => $description, ':now' => $now]);
    }

    /**
     * Delete a segment and all its memberships.
     *
     * @return bool True if the segment existed and was removed.
     */
    public function deleteSegment(string $name): bool
    {
        $name = trim($name);
        $this->db()->prepare(
            'DELETE FROM user_segment_members WHERE segment = :name'
        )->execute([':name' => $name]);
        $stmt = $this->db()->prepare('DELETE FROM user_segments WHERE name = :name');
        $stmt->execute([':name' => $name]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return all defined segments ordered by name.
     *
     * @return list<array<string,mixed>>
     */
    public function allSegments(): array
    {
        $stmt = $this->db()->query(
            'SELECT id, name, description, created_at FROM user_segments ORDER BY name ASC'
        );
        if ($stmt === false) {
            return [];
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // ── membership ────────────────────────────────────────────────────────────

    /**
     * Add a user to a segment (idempotent).
     *
     * @throws \InvalidArgumentException on empty segment or user_id.
     */
    public function addUser(string $segment, string $userId): void
    {
        [$segment, $userId] = $this->validate($segment, $userId);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $sql = 'INSERT OR IGNORE INTO user_segment_members (segment, user_id, added_at)
                    VALUES (:seg, :uid, :now)';
        } else {
            $sql = 'INSERT IGNORE INTO user_segment_members (segment, user_id, added_at)
                    VALUES (:seg, :uid, :now)';
        }
        $this->db()->prepare($sql)->execute([':seg' => $segment, ':uid' => $userId, ':now' => $now]);
    }

    /**
     * Remove a user from a segment.
     *
     * @return bool True if the membership existed and was removed.
     */
    public function removeUser(string $segment, string $userId): bool
    {
        [$segment, $userId] = $this->validate($segment, $userId);
        $stmt = $this->db()->prepare(
            'DELETE FROM user_segment_members WHERE segment = :seg AND user_id = :uid'
        );
        $stmt->execute([':seg' => $segment, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Return true if the user is a member of the segment.
     */
    public function isMember(string $segment, string $userId): bool
    {
        [$segment, $userId] = $this->validate($segment, $userId);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM user_segment_members WHERE segment = :seg AND user_id = :uid'
        );
        $stmt->execute([':seg' => $segment, ':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Return all user IDs in a segment, ordered alphabetically.
     *
     * @return list<string>
     */
    public function usersIn(string $segment): array
    {
        $stmt = $this->db()->prepare(
            'SELECT user_id FROM user_segment_members WHERE segment = :seg ORDER BY user_id ASC'
        );
        $stmt->execute([':seg' => trim($segment)]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Return the count of members in a segment.
     */
    public function memberCount(string $segment): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM user_segment_members WHERE segment = :seg'
        );
        $stmt->execute([':seg' => trim($segment)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Return all segment names a user belongs to, alphabetically.
     *
     * @return list<string>
     */
    public function segmentsFor(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT segment FROM user_segment_members WHERE user_id = :uid ORDER BY segment ASC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Remove a user from all segments.
     *
     * @return int Number of memberships removed.
     */
    public function removeFromAll(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM user_segment_members WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->rowCount();
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function validate(string $segment, string $userId): array
    {
        $segment = trim($segment);
        $userId  = trim($userId);
        if ($segment === '') {
            throw new \InvalidArgumentException('segment must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$segment, $userId];
    }
}
