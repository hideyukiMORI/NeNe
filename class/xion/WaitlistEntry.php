<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * WaitlistEntry — product / feature / event waitlist management.
 *
 * Users join a named waitlist and are assigned a sequential position.
 * When capacity opens, the operator invites users from the front of the queue.
 * Invitations expire if the user does not act; they can also voluntarily leave.
 *
 * ## Usage
 *
 * ```php
 * $wl = new WaitlistEntry($pdo);
 *
 * // Join
 * $id  = $wl->join('product-launch', 'user-42');
 * $pos = $wl->position('product-launch', 'user-42');  // 1
 *
 * // Invite from the front of the queue
 * $invited = $wl->inviteNext('product-launch', 3);    // array of entries
 *
 * // User accepts
 * $wl->accept($id);
 *
 * // Queries
 * $list  = $wl->listWaiting('product-launch');
 * $count = $wl->countWaiting('product-launch');
 * $entry = $wl->findEntry('product-launch', 'user-42');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE waitlist_entries (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     list_name   VARCHAR(100) NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'waiting',
 *     invited_at  DATETIME     NULL,
 *     accepted_at DATETIME     NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (list_name, user_id)
 * );
 * ```
 */
final class WaitlistEntry
{
    public const STATUS_WAITING  = 'waiting';
    public const STATUS_INVITED  = 'invited';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_LEFT     = 'left';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add a user to a waitlist.
     *
     * @return int Row ID of the new entry.
     * @throws \InvalidArgumentException on empty listName or userId.
     * @throws \RuntimeException if the user is already on the waitlist.
     */
    public function join(string $listName, string $userId): int
    {
        [$listName, $userId] = $this->validate($listName, $userId);

        $stmt = $this->db()->prepare(
            'INSERT INTO waitlist_entries (list_name, user_id, status)
             VALUES (:list, :uid, :status)'
        );
        try {
            $stmt->execute([
                ':list'   => $listName,
                ':uid'    => $userId,
                ':status' => self::STATUS_WAITING,
            ]);
        } catch (\PDOException $e) {
            throw new \RuntimeException("User {$userId} is already on waitlist {$listName}.", 0, $e);
        }
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find an entry for a specific user on a specific list.
     *
     * @return array<string,mixed>|null
     */
    public function findEntry(string $listName, string $userId): ?array
    {
        [$listName, $userId] = $this->validate($listName, $userId);
        $stmt = $this->db()->prepare(
            'SELECT * FROM waitlist_entries WHERE list_name = :list AND user_id = :uid'
        );
        $stmt->execute([':list' => $listName, ':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find a single entry by primary key.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM waitlist_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Mark a user's entry as invited.
     *
     * @return bool True if found in waiting state and updated.
     */
    public function invite(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE waitlist_entries SET status = :status, invited_at = :now
             WHERE id = :id AND status = :waiting'
        );
        $stmt->execute([
            ':status'  => self::STATUS_INVITED,
            ':now'     => $now,
            ':id'      => $id,
            ':waiting' => self::STATUS_WAITING,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a user's entry as accepted.
     *
     * @return bool True if found in invited state and updated.
     */
    public function accept(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE waitlist_entries SET status = :status, accepted_at = :now
             WHERE id = :id AND status = :invited'
        );
        $stmt->execute([
            ':status'  => self::STATUS_ACCEPTED,
            ':now'     => $now,
            ':id'      => $id,
            ':invited' => self::STATUS_INVITED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a user from the waitlist (voluntarily leaving).
     *
     * @return bool True if found and updated.
     */
    public function leave(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE waitlist_entries SET status = :status
             WHERE id = :id AND status IN (:waiting, :invited)'
        );
        $stmt->execute([
            ':status'  => self::STATUS_LEFT,
            ':id'      => $id,
            ':waiting' => self::STATUS_WAITING,
            ':invited' => self::STATUS_INVITED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete an entry permanently.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM waitlist_entries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Invite the next N users from the front of the waiting queue.
     *
     * @return list<array<string,mixed>> Updated entries.
     */
    public function inviteNext(string $listName, int $count = 1): array
    {
        $listName = trim($listName);
        $stmt     = $this->db()->prepare(
            'SELECT id FROM waitlist_entries
             WHERE list_name = :list AND status = :waiting
             ORDER BY id ASC
             LIMIT :cnt'
        );
        $stmt->bindValue(':list', $listName);
        $stmt->bindValue(':waiting', self::STATUS_WAITING);
        $stmt->bindValue(':cnt', $count, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updated = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $this->invite($id);
            $entry = $this->find($id);
            if ($entry !== null) {
                $updated[] = $entry;
            }
        }
        return $updated;
    }

    /**
     * List waiting-status entries for a list (oldest first = queue order).
     *
     * @return list<array<string,mixed>>
     */
    public function listWaiting(string $listName): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM waitlist_entries
             WHERE list_name = :list AND status = :waiting
             ORDER BY id ASC'
        );
        $stmt->execute([':list' => trim($listName), ':waiting' => self::STATUS_WAITING]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count users currently waiting.
     */
    public function countWaiting(string $listName): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM waitlist_entries
             WHERE list_name = :list AND status = :waiting'
        );
        $stmt->execute([':list' => trim($listName), ':waiting' => self::STATUS_WAITING]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Return the 1-based queue position of a waiting user.
     * Returns null if the user is not on the waitlist or not in waiting status.
     */
    public function position(string $listName, string $userId): ?int
    {
        [$listName, $userId] = $this->validate($listName, $userId);
        $entry = $this->findEntry($listName, $userId);
        if ($entry === null || $entry['status'] !== self::STATUS_WAITING) {
            return null;
        }
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM waitlist_entries
             WHERE list_name = :list AND status = :waiting AND id <= :id'
        );
        $stmt->execute([
            ':list'    => $listName,
            ':waiting' => self::STATUS_WAITING,
            ':id'      => (int)$entry['id'],
        ]);
        $pos = (int)$stmt->fetchColumn();
        return $pos > 0 ? $pos : null;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function validate(string $listName, string $userId): array
    {
        $listName = trim($listName);
        $userId   = trim($userId);
        if ($listName === '') {
            throw new \InvalidArgumentException('listName must not be empty.');
        }
        if ($userId === '') {
            throw new \InvalidArgumentException('userId must not be empty.');
        }
        return [$listName, $userId];
    }
}
