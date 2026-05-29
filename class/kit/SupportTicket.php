<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * SupportTicket — simple help desk ticket queue with reply thread.
 *
 * Manages customer/user support tickets with a status lifecycle
 * (open → in_progress → resolved → closed) and an append-only reply
 * thread. Tickets belong to a user (reporter) and can be assigned to
 * a staff member.
 *
 * ## Usage
 *
 * ```php
 * $st = new SupportTicket($pdo);
 *
 * // Open a ticket
 * $id = $st->open('user-1', 'Cannot log in', 'I get error 500 when...');
 *
 * // Staff workflow
 * $st->assign($id, 'staff-1');
 * $st->addReply($id, 'staff-1', 'We are looking into this.');
 * $st->resolve($id, 'staff-1');
 *
 * // Query
 * $open    = $st->openTickets();
 * $mine    = $st->forUser('user-1');
 * $thread  = $st->replies($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE support_tickets (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     subject     VARCHAR(255) NOT NULL,
 *     body        TEXT         NOT NULL DEFAULT '',
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'open',
 *     priority    VARCHAR(20)  NOT NULL DEFAULT 'normal',
 *     assigned_to VARCHAR(255) NOT NULL DEFAULT '',
 *     resolved_at DATETIME     NULL,
 *     closed_at   DATETIME     NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE ticket_replies (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     ticket_id  INTEGER      NOT NULL,
 *     author_id  VARCHAR(255) NOT NULL,
 *     body       TEXT         NOT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class SupportTicket
{
    public const STATUS_OPEN        = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED    = 'resolved';
    public const STATUS_CLOSED      = 'closed';

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Open a new support ticket.
     *
     * @return int Row ID.
     * @throws \InvalidArgumentException on empty fields.
     */
    public function open(
        string $userId,
        string $subject,
        string $body = '',
        string $priority = self::PRIORITY_NORMAL
    ): int {
        $userId  = trim($userId);
        $subject = trim($subject);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($subject === '') {
            throw new \InvalidArgumentException('subject must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO support_tickets (user_id, subject, body, priority)
             VALUES (:uid, :subj, :body, :prio)'
        );
        $stmt->execute([
            ':uid'  => $userId,
            ':subj' => $subject,
            ':body' => $body,
            ':prio' => $priority,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a ticket by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM support_tickets WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Assign a ticket to a staff member and move to in_progress.
     *
     * @return bool True if found and updated.
     */
    public function assign(int $id, string $assignedTo): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE support_tickets
             SET assigned_to = :staff, status = :status, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            ':staff'  => trim($assignedTo),
            ':status' => self::STATUS_IN_PROGRESS,
            ':now'    => $now,
            ':id'     => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a ticket as resolved (only from open or in_progress).
     *
     * @return bool True if found and transitioned.
     */
    public function resolve(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE support_tickets
             SET status = :resolved, resolved_at = :now, updated_at = :now
             WHERE id = :id AND (status = :open OR status = :ip)'
        );
        $stmt->execute([
            ':resolved' => self::STATUS_RESOLVED,
            ':now'      => $now,
            ':id'       => $id,
            ':open'     => self::STATUS_OPEN,
            ':ip'       => self::STATUS_IN_PROGRESS,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Close a ticket (final state).
     *
     * @return bool True if found and transitioned.
     */
    public function close(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE support_tickets
             SET status = :closed, closed_at = :now, updated_at = :now
             WHERE id = :id AND status != :closed'
        );
        $stmt->execute([
            ':closed' => self::STATUS_CLOSED,
            ':now'    => $now,
            ':id'     => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reopen a closed/resolved ticket.
     *
     * @return bool True if found and transitioned.
     */
    public function reopen(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE support_tickets
             SET status = :open, resolved_at = NULL, closed_at = NULL, updated_at = :now
             WHERE id = :id AND (status = :resolved OR status = :closed)'
        );
        $stmt->execute([
            ':open'     => self::STATUS_OPEN,
            ':now'      => $now,
            ':id'       => $id,
            ':resolved' => self::STATUS_RESOLVED,
            ':closed'   => self::STATUS_CLOSED,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Add a reply to a ticket.
     *
     * @return int Reply row ID.
     * @throws \InvalidArgumentException on empty body.
     */
    public function addReply(int $ticketId, string $authorId, string $body): int
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO ticket_replies (ticket_id, author_id, body)
             VALUES (:tid, :author, :body)'
        );
        $stmt->execute([':tid' => $ticketId, ':author' => trim($authorId), ':body' => $body]);

        // Touch updated_at on the ticket
        $this->db()->prepare('UPDATE support_tickets SET updated_at = :now WHERE id = :id')
            ->execute([':now' => $now, ':id' => $ticketId]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * List all replies for a ticket (oldest first).
     *
     * @return list<array<string,mixed>>
     */
    public function replies(int $ticketId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, ticket_id, author_id, body, created_at
             FROM ticket_replies WHERE ticket_id = :tid ORDER BY id ASC'
        );
        $stmt->execute([':tid' => $ticketId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List tickets for a user (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, subject, body, status, priority, assigned_to,
                    resolved_at, closed_at, created_at, updated_at
             FROM support_tickets WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List open/in-progress tickets, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function openTickets(int $limit = 50): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, subject, status, priority, assigned_to, created_at, updated_at
             FROM support_tickets
             WHERE status = :open OR status = :ip
             ORDER BY id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':open', self::STATUS_OPEN);
        $stmt->bindValue(':ip', self::STATUS_IN_PROGRESS);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count tickets grouped by status.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT status, COUNT(*) AS cnt FROM support_tickets GROUP BY status'
        );
        $stmt->execute();
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['status']] = (int)$row['cnt'];
        }
        return $result;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
