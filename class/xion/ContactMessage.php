<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ContactMessage — customer contact form inbox management.
 *
 * Stores inbound contact form submissions and manages their lifecycle through
 * an inbox workflow: unread → read → replied / archived. Provides an inbox
 * view and bulk archive for cleared messages.
 *
 * ## Usage
 *
 * ```php
 * $cm = new ContactMessage($pdo);
 *
 * $id = $cm->submit('Alice', 'alice@example.com', 'Help!', 'I cannot log in.');
 *
 * $inbox = $cm->inbox(20, 0);
 * $cm->markRead($id);
 * $cm->markReplied($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE contact_messages (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     name        VARCHAR(255) NOT NULL,
 *     email       VARCHAR(255) NOT NULL,
 *     subject     VARCHAR(500) NOT NULL,
 *     body        TEXT         NOT NULL,
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'unread',
 *     ip_address  VARCHAR(45)  NULL,
 *     replied_at  DATETIME     NULL,
 *     submitted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ContactMessage
{
    public const STATUS_UNREAD   = 'unread';
    public const STATUS_READ     = 'read';
    public const STATUS_REPLIED  = 'replied';
    public const STATUS_ARCHIVED = 'archived';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Submit a new contact message.
     *
     * @return int Row ID of the new message.
     * @throws \InvalidArgumentException on empty name/email/subject/body.
     */
    public function submit(
        string $name,
        string $email,
        string $subject,
        string $body,
        ?string $ipAddress = null
    ): int {
        $name    = trim($name);
        $email   = trim($email);
        $subject = trim($subject);
        $body    = trim($body);
        if ($name === '') {
            throw new \InvalidArgumentException('name must not be empty.');
        }
        if ($email === '') {
            throw new \InvalidArgumentException('email must not be empty.');
        }
        if ($subject === '') {
            throw new \InvalidArgumentException('subject must not be empty.');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO contact_messages (name, email, subject, body, status, ip_address, submitted_at)
             VALUES (:name, :email, :subject, :body, :status, :ip, :now)'
        );
        $stmt->execute([
            ':name'    => $name,
            ':email'   => $email,
            ':subject' => $subject,
            ':body'    => $body,
            ':status'  => self::STATUS_UNREAD,
            ':ip'      => $ipAddress,
            ':now'     => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a message by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM contact_messages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Return inbox messages (non-archived), newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function inbox(int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, name, email, subject, status, submitted_at
             FROM contact_messages
             WHERE status != :archived
             ORDER BY submitted_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':archived', self::STATUS_ARCHIVED, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return unread message count.
     */
    public function unreadCount(): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) AS cnt FROM contact_messages WHERE status = :status'
        );
        $stmt->execute([':status' => self::STATUS_UNREAD]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row !== false ? $row['cnt'] : 0);
    }

    /**
     * Mark a message as read.
     *
     * @return bool True if found and updated.
     */
    public function markRead(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE contact_messages SET status = :status WHERE id = :id AND status = :unread'
        );
        $stmt->execute([
            ':status' => self::STATUS_READ,
            ':id'     => $id,
            ':unread' => self::STATUS_UNREAD,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a message as replied.
     *
     * @return bool True if found and updated.
     */
    public function markReplied(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE contact_messages SET status = :status, replied_at = :now WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_REPLIED, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Archive a message.
     *
     * @return bool True if found and archived.
     */
    public function archive(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE contact_messages SET status = :status WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_ARCHIVED, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a message (hard delete).
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM contact_messages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete archived messages older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeArchived(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM contact_messages WHERE status = :status AND submitted_at < :cutoff'
        );
        $stmt->execute([':status' => self::STATUS_ARCHIVED, ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
