<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * SupportTicket — lightweight help-desk ticketing with replies and status lifecycle.
 *
 * Users open tickets; agents reply and close them. Tickets can be assigned to agents.
 * All messages are append-only; tickets transition through defined statuses.
 *
 * Status lifecycle: `open` → `pending` | `closed` (can reopen: `closed` → `open`)
 *
 * ## Usage
 *
 * ```php
 * $st = new SupportTicket($pdo);
 *
 * // Open a ticket
 * $id = $st->open('user-1', 'Login broken', 'I cannot log in since yesterday');
 *
 * // Add a reply
 * $st->reply($id, 'agent-1', 'Can you share your browser?', isAgent: true);
 *
 * // Assign and change status
 * $st->assign($id, 'agent-1');
 * $st->close($id, 'agent-1');
 *
 * // List open tickets
 * $st->listByStatus('open');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE support_tickets (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     subject     VARCHAR(500) NOT NULL DEFAULT '',
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'open',
 *     assigned_to VARCHAR(255) NOT NULL DEFAULT '',
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE support_ticket_replies (
 *     id        INTEGER PRIMARY KEY AUTOINCREMENT,
 *     ticket_id INTEGER      NOT NULL,
 *     author_id VARCHAR(255) NOT NULL,
 *     body      TEXT         NOT NULL,
 *     is_agent  TINYINT(1)   NOT NULL DEFAULT 0,
 *     created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class SupportTicket
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Open a new support ticket.
     *
     * @return int  The new ticket ID.
     * @throws \InvalidArgumentException if user_id or subject is empty.
     */
    public function open(string $userId, string $subject, string $body = ''): int
    {
        $userId  = trim($userId);
        $subject = trim($subject);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($subject === '') {
            throw new \InvalidArgumentException('subject must not be empty.');
        }

        $db = $this->db();
        $db->prepare(
            'INSERT INTO support_tickets (user_id, subject) VALUES (:uid, :sub)'
        )->execute([':uid' => $userId, ':sub' => $subject]);
        $ticketId = (int)$db->lastInsertId();

        if ($body !== '') {
            $this->addReply($db, $ticketId, $userId, $body, false);
        }

        return $ticketId;
    }

    /**
     * Add a reply to a ticket.
     *
     * @param  bool   $isAgent  True if the reply is from an agent.
     * @return int  The new reply ID.
     * @throws \InvalidArgumentException if ticket not found or author/body empty.
     */
    public function reply(int $ticketId, string $authorId, string $body, bool $isAgent = false): int
    {
        $authorId = trim($authorId);
        $body     = trim($body);
        if ($authorId === '') {
            throw new \InvalidArgumentException('author_id must not be empty.');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }

        $db = $this->db();
        $id = $this->addReply($db, $ticketId, $authorId, $body, $isAgent);

        // Touch updated_at
        $db->prepare(
            'UPDATE support_tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':id' => $ticketId]);

        return $id;
    }

    /**
     * Assign a ticket to an agent.
     *
     * @return bool True if the ticket was found and updated.
     */
    public function assign(int $ticketId, string $agentId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE support_tickets
             SET assigned_to = :agent, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([':agent' => trim($agentId), ':id' => $ticketId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Set a ticket to 'pending' status (waiting on user).
     *
     * @return bool True if the ticket was open and updated.
     */
    public function pending(int $ticketId): bool
    {
        return $this->setStatus($ticketId, 'pending', ['open']);
    }

    /**
     * Reopen a closed ticket.
     *
     * @return bool True if the ticket was closed and reopened.
     */
    public function reopen(int $ticketId): bool
    {
        return $this->setStatus($ticketId, 'open', ['closed', 'pending']);
    }

    /**
     * Close a ticket.
     *
     * @return bool True if the ticket was not already closed.
     */
    public function close(int $ticketId, string $closedBy = ''): bool
    {
        return $this->setStatus($ticketId, 'closed', ['open', 'pending']);
    }

    /**
     * Get a ticket with all its replies.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $ticketId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, subject, status, assigned_to, created_at, updated_at
             FROM support_tickets WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ticket === false) {
            return null;
        }

        $replies = $this->db()->prepare(
            'SELECT id, author_id, body, is_agent, created_at
             FROM support_ticket_replies WHERE ticket_id = :id ORDER BY id ASC'
        );
        $replies->execute([':id' => $ticketId]);
        $ticket['replies'] = $replies->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $ticket;
    }

    /**
     * List tickets by status.
     *
     * @return list<array<string,mixed>>
     */
    public function listByStatus(string $status, int $limit = 50): array
    {
        $limit = max(1, $limit);
        $stmt  = $this->db()->prepare(
            "SELECT id, user_id, subject, status, assigned_to, created_at, updated_at
             FROM support_tickets WHERE status = :status
             ORDER BY id DESC LIMIT {$limit}"
        );
        $stmt->execute([':status' => $status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List all tickets for a user.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, subject, status, assigned_to, created_at, updated_at
             FROM support_tickets WHERE user_id = :uid ORDER BY id DESC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count tickets by status.
     */
    public function count(?string $status = null): int
    {
        if ($status !== null) {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM support_tickets WHERE status = :status');
            $stmt->execute([':status' => $status]);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM support_tickets');
            $stmt->execute();
        }
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function addReply(PDO $db, int $ticketId, string $authorId, string $body, bool $isAgent): int
    {
        $db->prepare(
            'INSERT INTO support_ticket_replies (ticket_id, author_id, body, is_agent)
             VALUES (:tid, :auth, :body, :agent)'
        )->execute([':tid' => $ticketId, ':auth' => $authorId, ':body' => $body, ':agent' => $isAgent ? 1 : 0]);
        return (int)$db->lastInsertId();
    }

    /**
     * @param list<string> $allowedFrom
     */
    private function setStatus(int $ticketId, string $newStatus, array $allowedFrom): bool
    {
        $in   = implode(',', array_fill(0, count($allowedFrom), '?'));
        $args = array_merge([$newStatus, $ticketId], $allowedFrom);
        $stmt = $this->db()->prepare(
            "UPDATE support_tickets SET status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status IN ({$in})"
        );
        $stmt->execute($args);
        return $stmt->rowCount() > 0;
    }
}
