<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * Waitlist — manage sign-ups for gated access (beta, launches, events).
 *
 * Users join a named waitlist and are assigned a sequential position.
 * The waitlist owner invites the next N users, moving them to 'invited' status.
 * Users confirm their invite to become 'confirmed'.
 *
 * Status lifecycle: `waiting` → `invited` → `confirmed` (or `cancelled`)
 *
 * ## Usage
 *
 * ```php
 * $wl = new Waitlist($pdo);
 *
 * // Join
 * $pos = $wl->join('beta', 'user-1');
 *
 * // Invite next 10
 * $invited = $wl->inviteNext('beta', 10);
 *
 * // User confirms
 * $wl->confirm('beta', 'user-1');
 *
 * // Check status
 * $wl->status('beta', 'user-1'); // 'waiting'|'invited'|'confirmed'|'cancelled'|null
 *
 * // Position in queue
 * $wl->position('beta', 'user-1'); // 3 (1-based among waiting)
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE waitlist (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     list_name   VARCHAR(100) NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'waiting',
 *     joined_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     invited_at  DATETIME     DEFAULT NULL,
 *     confirmed_at DATETIME    DEFAULT NULL,
 *     UNIQUE (list_name, user_id)
 * );
 * ```
 */
final class Waitlist
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a user to a waitlist.
     *
     * Idempotent — joining twice returns the existing position.
     *
     * @return int  Position in the queue (1-based among 'waiting' entries).
     * @throws \InvalidArgumentException if list_name or user_id is empty.
     */
    public function join(string $listName, string $userId): int
    {
        [$listName, $userId] = $this->normalise($listName, $userId);

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO waitlist (list_name, user_id) VALUES (:list, :user)'
            : 'INSERT IGNORE INTO waitlist (list_name, user_id) VALUES (:list, :user)';

        $db->prepare($sql)->execute([':list' => $listName, ':user' => $userId]);

        return $this->position($listName, $userId) ?? 0;
    }

    /**
     * Invite the next `$count` waiting users.
     *
     * Invites are given in sign-up order (id ASC). Returns the list of invited user IDs.
     *
     * @return list<string> User IDs that were invited.
     */
    public function inviteNext(string $listName, int $count = 10): array
    {
        $count = max(1, $count);
        $db    = $this->db();

        // Fetch next N waiting users
        $stmt = $db->prepare(
            "SELECT id, user_id FROM waitlist
             WHERE list_name = :list AND status = 'waiting'
             ORDER BY id ASC
             LIMIT {$count}"
        );
        $stmt->execute([':list' => $listName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $ids = array_column($rows, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));

        $db->prepare(
            "UPDATE waitlist SET status = 'invited', invited_at = CURRENT_TIMESTAMP
             WHERE id IN ({$in})"
        )->execute($ids);

        return array_column($rows, 'user_id');
    }

    /**
     * Confirm an invite (user accepts their invitation).
     *
     * @return bool True if the user was in 'invited' status and was confirmed.
     */
    public function confirm(string $listName, string $userId): bool
    {
        [$listName, $userId] = $this->normalise($listName, $userId);
        $stmt = $this->db()->prepare(
            "UPDATE waitlist
             SET status = 'confirmed', confirmed_at = CURRENT_TIMESTAMP
             WHERE list_name = :list AND user_id = :user AND status = 'invited'"
        );
        $stmt->execute([':list' => $listName, ':user' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel a waiting or invited user's spot.
     *
     * @return bool True if the status was changed.
     */
    public function cancel(string $listName, string $userId): bool
    {
        [$listName, $userId] = $this->normalise($listName, $userId);
        $stmt = $this->db()->prepare(
            "UPDATE waitlist
             SET status = 'cancelled'
             WHERE list_name = :list AND user_id = :user AND status IN ('waiting', 'invited')"
        );
        $stmt->execute([':list' => $listName, ':user' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get the current status of a user on a waitlist.
     *
     * @return 'waiting'|'invited'|'confirmed'|'cancelled'|null
     */
    public function status(string $listName, string $userId): ?string
    {
        [$listName, $userId] = $this->normalise($listName, $userId);
        $stmt = $this->db()->prepare(
            'SELECT status FROM waitlist WHERE list_name = :list AND user_id = :user LIMIT 1'
        );
        $stmt->execute([':list' => $listName, ':user' => $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Get the user's 1-based position among 'waiting' entries.
     *
     * Returns null if the user is not on the waitlist or not 'waiting'.
     */
    public function position(string $listName, string $userId): ?int
    {
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) + 1
             FROM waitlist
             WHERE list_name = :list AND status = 'waiting'
               AND id < (
                   SELECT id FROM waitlist
                   WHERE list_name = :list2 AND user_id = :user AND status = 'waiting'
                   LIMIT 1
               )"
        );
        $stmt->execute([':list' => $listName, ':list2' => $listName, ':user' => $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int)$val : null;
    }

    /**
     * Count entries by status.
     */
    public function count(string $listName, string $status = 'waiting'): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM waitlist WHERE list_name = :list AND status = :status'
        );
        $stmt->execute([':list' => $listName, ':status' => $status]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $listName, string $userId): array
    {
        $listName = trim($listName);
        $userId   = trim($userId);
        if ($listName === '') {
            throw new \InvalidArgumentException('list_name must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return [$listName, $userId];
    }
}
