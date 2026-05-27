<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * EmailBounce — email bounce and complaint tracking for delivery health management.
 *
 * Records hard/soft bounce events and spam complaints received from email
 * service provider (ESP) webhooks. Provides a suppression list to prevent
 * sending to addresses that have bounced or complained.
 *
 * Distinct from:
 * - `EmailQueue`  (outbound email delivery queue)
 * - `EmailVerification` (email address ownership verification)
 * - `NewsletterSubscription` (opt-in/opt-out management)
 *
 * ## Usage
 *
 * ```php
 * $eb = new EmailBounce($pdo);
 *
 * $eb->record('user@example.com', EmailBounce::TYPE_HARD, '550 No such user');
 * $eb->isSuppressed('user@example.com'); // true — hard bounces are always suppressed
 *
 * $eb->recordComplaint('other@example.com');
 * $eb->isSuppressed('other@example.com'); // true
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE email_bounces (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     email       VARCHAR(255) NOT NULL,
 *     type        VARCHAR(20)  NOT NULL,
 *     reason      TEXT         NULL,
 *     suppressed  INTEGER      NOT NULL DEFAULT 1,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class EmailBounce
{
    public const TYPE_HARD      = 'hard';
    public const TYPE_SOFT      = 'soft';
    public const TYPE_COMPLAINT = 'complaint';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a bounce event.
     *
     * Hard bounces and complaints are always suppressed.
     * Soft bounces are optionally suppressed ($suppress = false by default).
     *
     * @param  bool|null $suppress Whether to add to the suppression list (default true for hard, false for soft).
     * @return int New bounce record ID.
     * @throws \InvalidArgumentException on empty email or invalid type.
     */
    public function record(
        string $email,
        string $type,
        ?string $reason = null,
        ?bool $suppress = null
    ): int {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException('email must not be empty.');
        }
        if (!in_array($type, [self::TYPE_HARD, self::TYPE_SOFT, self::TYPE_COMPLAINT], true)) {
            throw new \InvalidArgumentException('Invalid bounce type.');
        }

        // Default suppression: hard and complaint always suppressed; soft not suppressed
        if ($suppress === null) {
            $suppress = ($type === self::TYPE_HARD || $type === self::TYPE_COMPLAINT);
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO email_bounces (email, type, reason, suppressed, created_at)
             VALUES (:email, :type, :reason, :sup, :now)'
        );
        $stmt->execute([
            ':email'  => $email,
            ':type'   => $type,
            ':reason' => $reason,
            ':sup'    => $suppress ? 1 : 0,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Record a spam complaint (shorthand for TYPE_COMPLAINT).
     *
     * @return int New record ID.
     */
    public function recordComplaint(string $email, ?string $reason = null): int
    {
        return $this->record($email, self::TYPE_COMPLAINT, $reason);
    }

    /**
     * Whether an email address is on the suppression list.
     *
     * Returns true if any suppressed record exists for the address.
     */
    public function isSuppressed(string $email): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM email_bounces WHERE email = :email AND suppressed = 1'
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Remove all bounce/complaint records for an email address.
     * Use after a manual review confirms the address is valid again.
     *
     * @return int Rows deleted.
     */
    public function removeSupression(string $email): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM email_bounces WHERE email = :email'
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        return $stmt->rowCount();
    }

    /**
     * All bounce records for an email address.
     *
     * @return list<array<string,mixed>>
     */
    public function forEmail(string $email): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM email_bounces WHERE email = :email ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':email' => strtolower(trim($email))]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All suppressed email addresses.
     *
     * @return list<string>
     */
    public function suppressedList(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT DISTINCT email FROM email_bounces WHERE suppressed = 1 ORDER BY email ASC'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Total bounce records, optionally filtered by type.
     */
    public function count(?string $type = null): int
    {
        if ($type !== null) {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) FROM email_bounces WHERE type = :type'
            );
            $stmt->execute([':type' => $type]);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) FROM email_bounces');
            $stmt->execute();
        }
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete bounce records older than $cutoff.
     *
     * @return int Rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM email_bounces WHERE created_at < :cutoff'
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
