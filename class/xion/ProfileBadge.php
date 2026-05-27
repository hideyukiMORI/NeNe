<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Gamification badge award and management.
 *
 * Each badge is identified by a string key (e.g. 'first_purchase', 'power_user').
 * Award is idempotent — awarding a badge the user already holds is a no-op and
 * returns false.  Revoke removes it permanently.
 *
 * ## Usage
 *
 * ```php
 * $pb = new ProfileBadge($pdo);
 *
 * // Award a badge
 * $pb->award('user-1', 'first_purchase', 'admin');
 *
 * // Check and query
 * $pb->hasBadge('user-1', 'first_purchase');  // true
 * $pb->userBadges('user-1');                  // [['badge_key' => ...], ...]
 * $pb->usersWithBadge('first_purchase');       // ['user-1', ...]
 *
 * // Revoke
 * $pb->revoke('user-1', 'first_purchase');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE profile_badges (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     badge_key   VARCHAR(100) NOT NULL,
 *     awarded_by  VARCHAR(255) NULL,
 *     awarded_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, badge_key)
 * );
 * ```
 */
final class ProfileBadge
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    // ── award ─────────────────────────────────────────────────────────────────

    /**
     * Award a badge to a user.
     *
     * Returns true if the badge was newly awarded, false if already held
     * (idempotent — no duplicate row is created).
     *
     * @throws \InvalidArgumentException if userId or badgeKey are empty.
     */
    public function award(
        string $userId,
        string $badgeKey,
        ?string $awardedBy = null,
    ): bool {
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty.');
        }

        if ($badgeKey === '') {
            throw new \InvalidArgumentException('badgeKey must not be empty.');
        }

        if ($this->hasBadge($userId, $badgeKey)) {
            return false;
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO profile_badges (user_id, badge_key, awarded_by)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $badgeKey, $awardedBy]);
        return true;
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    /**
     * Revoke a badge from a user.
     *
     * Returns true if the badge was removed, false if the user did not hold it.
     */
    public function revoke(string $userId, string $badgeKey): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM profile_badges WHERE user_id = ? AND badge_key = ?'
        );
        $stmt->execute([$userId, $badgeKey]);
        return $stmt->rowCount() > 0;
    }

    // ── queries ───────────────────────────────────────────────────────────────

    /**
     * Check whether a user holds a given badge.
     */
    public function hasBadge(string $userId, string $badgeKey): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT 1 FROM profile_badges WHERE user_id = ? AND badge_key = ?'
        );
        $stmt->execute([$userId, $badgeKey]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Return all badges held by a user, ordered by awarded_at asc.
     *
     * @return array<int,array<string,mixed>>
     */
    public function userBadges(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM profile_badges WHERE user_id = ? ORDER BY awarded_at ASC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all user IDs that hold a given badge.
     *
     * @return string[]
     */
    public function usersWithBadge(string $badgeKey): array
    {
        $stmt = $this->db()->prepare(
            'SELECT user_id FROM profile_badges WHERE badge_key = ? ORDER BY awarded_at ASC'
        );
        $stmt->execute([$badgeKey]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Return the count of unique users who hold a badge.
     */
    public function countForBadge(string $badgeKey): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM profile_badges WHERE badge_key = ?'
        );
        $stmt->execute([$badgeKey]);
        return (int)$stmt->fetchColumn();
    }

    // ── revokeAll ─────────────────────────────────────────────────────────────

    /**
     * Revoke all badges from a user (e.g. account deletion cleanup).
     *
     * @return int Number of badges removed.
     */
    public function revokeAll(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM profile_badges WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->rowCount();
    }
}
