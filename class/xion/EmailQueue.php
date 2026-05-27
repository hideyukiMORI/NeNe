<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * EmailQueue — DB-backed outgoing email queue with retry and exponential backoff.
 *
 * Decouples email sending from the request lifecycle. Emails are enqueued
 * immediately and sent asynchronously by a worker. Failed sends are retried
 * with exponential backoff up to a configurable maximum.
 *
 * ## Usage
 *
 * ```php
 * $eq = new EmailQueue($pdo, maxAttempts: 3);
 *
 * // Enqueue
 * $id = $eq->enqueue('user@example.com', 'Welcome!', '<h1>Hi</h1>', 'text/html');
 *
 * // Worker: claim next due email
 * $email = $eq->claim();
 * if ($email) {
 *     // → send via Mailer
 *     $eq->markSent($email['id']);
 *     // or on failure:
 *     $eq->markFailed($email['id'], 'SMTP timeout');
 * }
 *
 * // Maintenance
 * $eq->purgeSent(30);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE email_queue (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     to_address  VARCHAR(255) NOT NULL,
 *     subject     VARCHAR(500) NOT NULL DEFAULT '',
 *     body        TEXT         NOT NULL DEFAULT '',
 *     content_type VARCHAR(50) NOT NULL DEFAULT 'text/plain',
 *     status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     attempts    INTEGER      NOT NULL DEFAULT 0,
 *     max_attempts INTEGER     NOT NULL DEFAULT 3,
 *     error       TEXT         NOT NULL DEFAULT '',
 *     send_after  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     sent_at     DATETIME     DEFAULT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class EmailQueue
{
    public function __construct(
        private readonly ?PDO $db = null,
        private readonly int $maxAttempts = 3
    ) {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add an email to the send queue.
     *
     * @return int The queue entry ID.
     * @throws \InvalidArgumentException if to_address is empty.
     */
    public function enqueue(
        string $toAddress,
        string $subject,
        string $body,
        string $contentType = 'text/plain'
    ): int {
        $toAddress = trim($toAddress);
        if ($toAddress === '') {
            throw new \InvalidArgumentException('to_address must not be empty.');
        }
        $db = $this->db();
        $db->prepare(
            'INSERT INTO email_queue (to_address, subject, body, content_type, max_attempts)
             VALUES (:to, :sub, :body, :ct, :max)'
        )->execute([
            ':to'   => $toAddress,
            ':sub'  => $subject,
            ':body' => $body,
            ':ct'   => $contentType,
            ':max'  => $this->maxAttempts,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Claim the next pending email that is due for sending.
     *
     * Increments attempt count and returns the record, or null if nothing is due.
     *
     * @return array<string,mixed>|null
     */
    public function claim(): ?array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $db   = $this->db();
        $stmt = $db->prepare(
            'SELECT id, to_address, subject, body, content_type, attempts, max_attempts
             FROM email_queue
             WHERE status = \'pending\' AND send_after <= :now
             ORDER BY send_after ASC LIMIT 1'
        );
        $stmt->execute([':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $db->prepare(
            'UPDATE email_queue SET attempts = attempts + 1 WHERE id = :id'
        )->execute([':id' => $row['id']]);

        $row['attempts'] = (int)$row['attempts'] + 1;
        return $row;
    }

    /**
     * Mark an email as successfully sent.
     *
     * @return bool True if the record was updated.
     */
    public function markSent(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE email_queue SET status = \'sent\', sent_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark an email as failed.
     *
     * If attempts < max_attempts the email is rescheduled with exponential
     * backoff (60 × 2^(attempts-1) seconds). Otherwise it is permanently failed.
     *
     * @return bool True if the record was updated.
     */
    public function markFailed(int $id, string $error = ''): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT attempts, max_attempts FROM email_queue WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        $attempts    = (int)$row['attempts'];
        $maxAttempts = (int)$row['max_attempts'];

        if ($attempts < $maxAttempts) {
            $delaySeconds = 60 * (2 ** ($attempts - 1));
            $sendAfter    = (new \DateTimeImmutable())->modify("+{$delaySeconds} seconds")->format('Y-m-d H:i:s');
            $upd = $this->db()->prepare(
                'UPDATE email_queue SET error = :err, send_after = :sa WHERE id = :id'
            );
            $upd->execute([':err' => $error, ':sa' => $sendAfter, ':id' => $id]);
        } else {
            $upd = $this->db()->prepare(
                'UPDATE email_queue SET status = \'failed\', error = :err WHERE id = :id'
            );
            $upd->execute([':err' => $error, ':id' => $id]);
        }

        return true;
    }

    /**
     * Find a queue entry by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, to_address, subject, body, content_type, status, attempts,
                    max_attempts, error, send_after, sent_at, created_at
             FROM email_queue WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Count queue entries, optionally by status.
     */
    public function count(?string $status = null): int
    {
        if ($status === null) {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM email_queue');
            $stmt->execute();
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM email_queue WHERE status = :s');
            $stmt->execute([':s' => $status]);
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete sent emails older than N days.
     *
     * @return int Number of rows deleted.
     */
    public function purgeSent(int $days): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'DELETE FROM email_queue WHERE status = \'sent\' AND sent_at < :cutoff'
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
