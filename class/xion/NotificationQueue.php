<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * NotificationQueue — channel-agnostic outbox-pattern notification queue.
 *
 * Queues notifications for delivery via email, SMS, push, webhook, or any
 * channel. A dispatcher process dequeues pending items, attempts delivery,
 * and marks them sent or failed. Failed items are retried up to a max-attempts
 * limit before being marked permanently failed.
 *
 * Distinct from EmailQueue (SMTP-specific, with From/To/Subject model).
 *
 * ## Usage
 *
 * ```php
 * $nq = new NotificationQueue($pdo);
 *
 * // Enqueue
 * $id = $nq->enqueue('user-42', 'push', 'New message', ['badge' => 1]);
 *
 * // Dispatcher loop
 * $batch = $nq->dequeue(10);
 * foreach ($batch as $item) {
 *     // attempt delivery …
 *     $nq->markSent($item['id']);
 *     // or on failure:
 *     $nq->markFailed($item['id'], 'Device unreachable');
 * }
 *
 * // Housekeeping
 * $nq->purgeSent(new \DateTimeImmutable('-7 days'));
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE notification_queue (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     recipient_id  VARCHAR(255) NOT NULL,
 *     channel       VARCHAR(50)  NOT NULL DEFAULT 'push',
 *     subject       VARCHAR(255) NOT NULL,
 *     payload       TEXT         NULL,
 *     status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     attempts      INTEGER      NOT NULL DEFAULT 0,
 *     max_attempts  INTEGER      NOT NULL DEFAULT 3,
 *     error_message TEXT         NULL,
 *     scheduled_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     sent_at       DATETIME     NULL,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class NotificationQueue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    public const CHANNEL_EMAIL   = 'email';
    public const CHANNEL_PUSH    = 'push';
    public const CHANNEL_SMS     = 'sms';
    public const CHANNEL_WEBHOOK = 'webhook';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Enqueue a notification.
     *
     * @param array<mixed>|string|null $payload Channel-specific data (array → JSON).
     * @return int Row ID.
     * @throws \InvalidArgumentException on empty recipientId or subject.
     */
    public function enqueue(
        string $recipientId,
        string $channel,
        string $subject,
        array|string|null $payload = null,
        int $maxAttempts = 3,
        ?\DateTimeImmutable $scheduledAt = null,
    ): int {
        $recipientId = trim($recipientId);
        $subject     = trim($subject);
        if ($recipientId === '') {
            throw new \InvalidArgumentException('recipientId must not be empty.');
        }
        if ($subject === '') {
            throw new \InvalidArgumentException('subject must not be empty.');
        }

        $scheduledStr = ($scheduledAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $payloadStr   = is_array($payload) ? json_encode($payload) : $payload;

        $stmt = $this->db()->prepare(
            'INSERT INTO notification_queue
                (recipient_id, channel, subject, payload, status, attempts, max_attempts, scheduled_at)
             VALUES (:rid, :chan, :sub, :payload, :status, 0, :max, :sched)'
        );
        $stmt->execute([
            ':rid'     => $recipientId,
            ':chan'    => trim($channel) ?: self::CHANNEL_PUSH,
            ':sub'     => $subject,
            ':payload' => $payloadStr,
            ':status'  => self::STATUS_PENDING,
            ':max'     => $maxAttempts,
            ':sched'   => $scheduledStr,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Find a single queue item by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM notification_queue WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch pending items due for delivery (attempts < max_attempts, scheduled_at <= now).
     *
     * @return list<array<string,mixed>>
     */
    public function dequeue(int $limit = 50): array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT * FROM notification_queue
             WHERE status = :status AND attempts < max_attempts AND scheduled_at <= :now
             ORDER BY scheduled_at ASC, id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':status', self::STATUS_PENDING);
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Mark a notification as successfully sent.
     *
     * @return bool True if found and updated.
     */
    public function markSent(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE notification_queue
             SET status = :status, sent_at = :now, attempts = attempts + 1
             WHERE id = :id'
        );
        $stmt->execute([':status' => self::STATUS_SENT, ':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a delivery attempt as failed (increments attempts counter).
     * If attempts reaches max_attempts, status is set to 'failed'.
     *
     * @return bool True if found and updated.
     */
    public function markFailed(int $id, ?string $errorMessage = null): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE notification_queue
             SET attempts = attempts + 1,
                 error_message = :err,
                 status = CASE WHEN attempts + 1 >= max_attempts THEN :failed ELSE :pending END
             WHERE id = :id AND status = :pending2'
        );
        $stmt->execute([
            ':err'     => $errorMessage,
            ':failed'  => self::STATUS_FAILED,
            ':pending' => self::STATUS_PENDING,
            ':id'      => $id,
            ':pending2' => self::STATUS_PENDING,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a queue item permanently.
     *
     * @return bool True if found and deleted.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db()->prepare('DELETE FROM notification_queue WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List pending notifications for a specific recipient.
     *
     * @return list<array<string,mixed>>
     */
    public function pendingFor(string $recipientId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM notification_queue
             WHERE recipient_id = :rid AND status = :status
             ORDER BY scheduled_at ASC'
        );
        $stmt->execute([':rid' => trim($recipientId), ':status' => self::STATUS_PENDING]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete all sent items older than the given cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeSent(\DateTimeImmutable $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM notification_queue WHERE status = :status AND sent_at < :cutoff'
        );
        $stmt->execute([':status' => self::STATUS_SENT, ':cutoff' => $cutoff->format('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
