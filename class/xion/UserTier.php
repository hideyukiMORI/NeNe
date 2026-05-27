<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * UserTier — gamification tier/membership level assignment with history.
 *
 * Tracks each user's current tier (e.g. bronze, silver, gold, platinum)
 * and maintains a full assignment history. The current tier is stored in
 * a separate table with a UNIQUE constraint so lookups are O(1).
 *
 * Tier names are arbitrary strings; the class imposes no fixed tier list.
 * You can define constants for your tier names in the caller.
 *
 * ## Usage
 *
 * ```php
 * $t = new UserTier($pdo);
 *
 * $t->assign('user-1', 'bronze');
 * $t->assign('user-1', 'silver', 'Reached 1000 points');
 *
 * echo $t->current('user-1');  // 'silver'
 * $t->usersInTier('silver');   // ['user-1', ...]
 * $t->history('user-1');       // all past assignments
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE user_tiers (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     tier        VARCHAR(50)  NOT NULL,
 *     assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     reason      TEXT         NULL,
 *     UNIQUE (user_id)
 * );
 *
 * CREATE TABLE user_tier_history (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     tier        VARCHAR(50)  NOT NULL,
 *     assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     reason      TEXT         NULL
 * );
 * ```
 */
final class UserTier
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Assign (or re-assign) a tier to a user.
     * Overwrites the current tier and appends an entry to history.
     *
     * @throws \InvalidArgumentException on empty user_id or tier.
     */
    public function assign(string $userId, string $tier, ?string $reason = null): void
    {
        $userId = trim($userId);
        $tier   = trim($tier);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($tier === '') {
            throw new \InvalidArgumentException('tier must not be empty.');
        }
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $driver = $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $upsert = 'INSERT INTO user_tiers (user_id, tier, assigned_at, reason)
                       VALUES (:uid, :tier, :now, :reason)
                       ON CONFLICT (user_id) DO UPDATE SET
                           tier = excluded.tier,
                           assigned_at = excluded.assigned_at,
                           reason = excluded.reason';
        } else {
            $upsert = 'INSERT INTO user_tiers (user_id, tier, assigned_at, reason)
                       VALUES (:uid, :tier, :now, :reason)
                       ON DUPLICATE KEY UPDATE
                           tier = VALUES(tier),
                           assigned_at = VALUES(assigned_at),
                           reason = VALUES(reason)';
        }
        $stmt = $this->db()->prepare($upsert);
        $stmt->execute([':uid' => $userId, ':tier' => $tier, ':now' => $now, ':reason' => $reason]);

        $hist = $this->db()->prepare(
            'INSERT INTO user_tier_history (user_id, tier, assigned_at, reason)
             VALUES (:uid, :tier, :now, :reason)'
        );
        $hist->execute([':uid' => $userId, ':tier' => $tier, ':now' => $now, ':reason' => $reason]);
    }

    /**
     * Return the current tier name for a user, or null if none assigned.
     */
    public function current(string $userId): ?string
    {
        $stmt = $this->db()->prepare(
            'SELECT tier FROM user_tiers WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => trim($userId)]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string)$val;
    }

    /**
     * Return the full tier assignment history for a user, most recent first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, tier, assigned_at, reason
             FROM user_tier_history WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return user IDs currently in a given tier.
     *
     * @return list<string>
     */
    public function usersInTier(string $tier): array
    {
        $stmt = $this->db()->prepare(
            'SELECT user_id FROM user_tiers WHERE tier = :tier ORDER BY user_id ASC'
        );
        $stmt->execute([':tier' => trim($tier)]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Return tier → count map for all currently assigned tiers.
     *
     * @return array<string,int>
     */
    public function countByTier(): array
    {
        $stmt = $this->db()->query(
            'SELECT tier, COUNT(*) AS cnt FROM user_tiers GROUP BY tier ORDER BY tier ASC'
        );
        if ($stmt === false) {
            return [];
        }
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string)$row['tier']] = (int)$row['cnt'];
        }
        return $result;
    }

    /**
     * Return true if a user has ever been assigned the given tier.
     */
    public function hasEverHad(string $userId, string $tier): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM user_tier_history WHERE user_id = :uid AND tier = :tier'
        );
        $stmt->execute([':uid' => trim($userId), ':tier' => trim($tier)]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Remove the current tier assignment for a user (keeps history).
     *
     * @return bool True if an assignment existed and was removed.
     */
    public function remove(string $userId): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM user_tiers WHERE user_id = :uid');
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ──────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
