<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * QueueTicket — take-a-number service queue with now-serving tracking.
 *
 * Models a physical/virtual "take a number" line per queue: customers `issue()`
 * a ticket, staff `callNext()` to advance the now-serving number, and a
 * waiting customer can see their `position()`. Distinct from `JobQueue` (FT73,
 * background work) and `TimeSlot` (FT203, booked appointments): this is an
 * ordered, human-facing service line.
 *
 * Ticket lifecycle: `waiting` → `serving` → `done`; a ticket may also be
 * `skipped`. Numbers increase per queue.
 *
 * ## Usage
 *
 * ```php
 * $q = new QueueTicket($pdo);
 *
 * $n1 = $q->issue('deli', 'Alice'); // 1
 * $n2 = $q->issue('deli', 'Bob');   // 2
 *
 * $q->waiting('deli');       // 2
 * $q->position('deli', $n2); // 2 (one ahead)
 * $q->callNext('deli');      // 1 — Alice now serving
 * $q->nowServing('deli');    // 1
 * $q->position('deli', $n2); // 1 (next up)
 * $q->callNext('deli');      // 2 — Alice done, Bob serving
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE queue_tickets (
 *     id        INTEGER PRIMARY KEY AUTOINCREMENT,
 *     queue     VARCHAR(100) NOT NULL,
 *     number    INTEGER      NOT NULL,
 *     label     VARCHAR(190) NOT NULL DEFAULT '',
 *     status    VARCHAR(10)  NOT NULL DEFAULT 'waiting',
 *     issued_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (queue, number)
 * );
 * ```
 */
final class QueueTicket
{
    public const string WAITING = 'waiting';
    public const string SERVING = 'serving';
    public const string DONE    = 'done';
    public const string SKIPPED = 'skipped';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Issue the next ticket number for a queue.
     *
     * @param  string $queue Queue name.
     * @param  string $label Optional label (customer name, etc.).
     * @return int           The assigned ticket number.
     * @throws \InvalidArgumentException on empty queue.
     */
    public function issue(string $queue, string $label = ''): int
    {
        $queue          = $this->validate($queue);
        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $next = $this->maxNumber($queue) + 1;
            $stmt = $db->prepare('INSERT INTO queue_tickets (queue, number, label, status) VALUES (?, ?, ?, ?)');
            $stmt->execute([$queue, $next, $label, self::WAITING]);
            if ($ownTransaction) {
                $db->commit();
            }

            return $next;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Complete the current serving ticket and start serving the next waiting
     * ticket (lowest number).
     *
     * @param  string $queue Queue name.
     * @return int|null      The newly-serving number, or null if none waiting.
     */
    public function callNext(string $queue): ?int
    {
        $queue          = $this->validate($queue);
        $db             = $this->db();
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            // Finish whoever is currently serving.
            $db->prepare('UPDATE queue_tickets SET status = ? WHERE queue = ? AND status = ?')
               ->execute([self::DONE, $queue, self::SERVING]);

            $sel = $db->prepare('SELECT number FROM queue_tickets WHERE queue = ? AND status = ? ORDER BY number ASC LIMIT 1');
            $sel->execute([$queue, self::WAITING]);
            $number = $sel->fetchColumn();

            if ($number === false) {
                if ($ownTransaction) {
                    $db->commit();
                }

                return null;
            }

            $db->prepare('UPDATE queue_tickets SET status = ? WHERE queue = ? AND number = ?')
               ->execute([self::SERVING, $queue, (int)$number]);

            if ($ownTransaction) {
                $db->commit();
            }

            return (int)$number;
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The currently-serving number for a queue, or null.
     */
    public function nowServing(string $queue): ?int
    {
        $queue = $this->validate($queue);
        $stmt  = $this->db()->prepare('SELECT number FROM queue_tickets WHERE queue = ? AND status = ? LIMIT 1');
        $stmt->execute([$queue, self::SERVING]);
        $n = $stmt->fetchColumn();

        return $n === false ? null : (int)$n;
    }

    /**
     * 1-based position of a waiting ticket (1 = next up), or null if it is not
     * waiting (already served, skipped, or unknown).
     */
    public function position(string $queue, int $number): ?int
    {
        $queue = $this->validate($queue);

        $own = $this->db()->prepare('SELECT 1 FROM queue_tickets WHERE queue = ? AND number = ? AND status = ?');
        $own->execute([$queue, $number, self::WAITING]);
        if ($own->fetchColumn() === false) {
            return null;
        }

        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM queue_tickets WHERE queue = ? AND status = ? AND number < ?'
        );
        $stmt->execute([$queue, self::WAITING, $number]);

        return (int)$stmt->fetchColumn() + 1;
    }

    /**
     * Number of waiting tickets in a queue.
     */
    public function waiting(string $queue): int
    {
        $queue = $this->validate($queue);
        $stmt  = $this->db()->prepare('SELECT COUNT(*) FROM queue_tickets WHERE queue = ? AND status = ?');
        $stmt->execute([$queue, self::WAITING]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Mark a ticket done. No-op if absent.
     */
    public function complete(string $queue, int $number): void
    {
        $this->setStatus($queue, $number, self::DONE);
    }

    /**
     * Skip a ticket (e.g. no-show). No-op if absent.
     */
    public function skip(string $queue, int $number): void
    {
        $this->setStatus($queue, $number, self::SKIPPED);
    }

    /**
     * Remove all tickets for a queue (numbering restarts). Returns rows removed.
     */
    public function reset(string $queue): int
    {
        $queue = $this->validate($queue);
        $stmt  = $this->db()->prepare('DELETE FROM queue_tickets WHERE queue = ?');
        $stmt->execute([$queue]);

        return $stmt->rowCount();
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function setStatus(string $queue, int $number, string $status): void
    {
        $queue = $this->validate($queue);
        $stmt  = $this->db()->prepare('UPDATE queue_tickets SET status = ? WHERE queue = ? AND number = ?');
        $stmt->execute([$status, $queue, $number]);
    }

    private function maxNumber(string $queue): int
    {
        $stmt = $this->db()->prepare('SELECT COALESCE(MAX(number), 0) FROM queue_tickets WHERE queue = ?');
        $stmt->execute([$queue]);

        return (int)$stmt->fetchColumn();
    }

    private function validate(string $queue): string
    {
        $queue = trim($queue);
        if ($queue === '') {
            throw new \InvalidArgumentException('Queue must not be empty.');
        }

        return $queue;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
