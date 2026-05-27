<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Reminder — user-set future reminders.
 *
 * Stores reminders for users to be triggered at a specified datetime.
 * Reminders can be attached to an optional target entity (task, event,
 * issue). The delivery mechanism (email, push, in-app) is outside scope;
 * this class manages the schedule and sent state.
 *
 * ## Usage
 *
 * ```php
 * $rm = new Reminder($pdo);
 *
 * // Set a reminder
 * $id = $rm->set('user-1', 'Review PR #42', '2026-06-01 09:00:00', 'pr', '42');
 *
 * // Poll due reminders (e.g. from a cron job)
 * $due = $rm->due();
 * foreach ($due as $r) {
 *     // deliver notification…
 *     $rm->markSent($r['id']);
 * }
 *
 * // User management
 * $list = $rm->forUser('user-1');
 * $rm->cancel($id, 'user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE reminders (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     message     TEXT         NOT NULL,
 *     remind_at   DATETIME     NOT NULL,
 *     entity_type VARCHAR(100) NOT NULL DEFAULT '',
 *     entity_id   VARCHAR(255) NOT NULL DEFAULT '',
 *     sent        TINYINT(1)   NOT NULL DEFAULT 0,
 *     sent_at     DATETIME     NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class Reminder
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Create a reminder for a user.
     *
     * @param  string $remindAt  ISO 8601 datetime string: 'Y-m-d H:i:s'.
     * @param  string $entityType  Optional: entity type the reminder relates to.
     * @param  string $entityId    Optional: entity ID the reminder relates to.
     * @return int Row ID of the new reminder.
     * @throws \InvalidArgumentException on empty fields.
     */
    public function set(
        string $userId,
        string $message,
        string $remindAt,
        string $entityType = '',
        string $entityId = ''
    ): int {
        $userId  = trim($userId);
        $message = trim($message);
        $remindAt = trim($remindAt);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($message === '') {
            throw new \InvalidArgumentException('message must not be empty.');
        }
        if ($remindAt === '') {
            throw new \InvalidArgumentException('remind_at must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO reminders (user_id, message, remind_at, entity_type, entity_id)
             VALUES (:uid, :msg, :at, :etype, :eid)'
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':msg'   => $message,
            ':at'    => $remindAt,
            ':etype' => trim($entityType),
            ':eid'   => trim($entityId),
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a reminder by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM reminders WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all reminders for a user (unsent first, then by remind_at ASC).
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId, bool $unsentOnly = false): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        if ($unsentOnly) {
            $stmt = $this->db()->prepare(
                'SELECT id, user_id, message, remind_at, entity_type, entity_id, sent, sent_at, created_at
                 FROM reminders
                 WHERE user_id = :uid AND sent = 0
                 ORDER BY remind_at ASC, id ASC'
            );
        } else {
            $stmt = $this->db()->prepare(
                'SELECT id, user_id, message, remind_at, entity_type, entity_id, sent, sent_at, created_at
                 FROM reminders
                 WHERE user_id = :uid
                 ORDER BY sent ASC, remind_at ASC, id ASC'
            );
        }
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return all unsent reminders whose remind_at is ≤ now.
     *
     * @return list<array<string,mixed>>
     */
    public function due(?string $asOf = null): array
    {
        $now  = $asOf ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, message, remind_at, entity_type, entity_id, sent, sent_at, created_at
             FROM reminders
             WHERE sent = 0 AND remind_at <= :now
             ORDER BY remind_at ASC, id ASC'
        );
        $stmt->execute([':now' => $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark a reminder as sent.
     *
     * @return bool True if found and updated.
     */
    public function markSent(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE reminders SET sent = 1, sent_at = :now WHERE id = :id AND sent = 0'
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel (delete) a reminder (ownership-enforced).
     *
     * @return bool True if found and deleted.
     */
    public function cancel(int $id, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM reminders WHERE id = :id AND user_id = :uid'
        );
        $stmt->execute([':id' => $id, ':uid' => trim($userId)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel all reminders for a user.
     *
     * @return int Number of rows deleted.
     */
    public function cancelAll(string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        $stmt = $this->db()->prepare('DELETE FROM reminders WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * Delete sent reminders older than a given number of days (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeSent(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM reminders WHERE sent = 1 AND sent_at <= :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }



    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
