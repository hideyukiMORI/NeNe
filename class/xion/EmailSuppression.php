<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * EmailSuppression — do-not-send list for email addresses.
 *
 * Tracks addresses that must not receive mail — hard bounces, spam
 * complaints, explicit unsubscribes, or manual blocks — so a sender can skip
 * them before dispatch and protect deliverability. Complements `EmailBounce`
 * (FT253, which records bounce *events*): this is the derived, deduplicated
 * suppression set keyed by address.
 *
 * Addresses are normalised to lowercase. The suppression set is the single
 * source of truth a send pipeline consults via {@see EmailSuppression::filter()}.
 *
 * ## Usage
 *
 * ```php
 * $sup = new EmailSuppression($pdo);
 *
 * $sup->suppress('user@example.com', EmailSuppression::REASON_BOUNCE);
 * $sup->suppress('spam@example.com', EmailSuppression::REASON_COMPLAINT);
 *
 * $sup->isSuppressed('USER@example.com'); // true (case-insensitive)
 * $sup->reasonFor('user@example.com');    // 'bounce'
 *
 * // Before a batch send, keep only deliverable addresses
 * $ok = $sup->filter(['a@x.com', 'user@example.com', 'b@x.com']);
 * // → ['a@x.com', 'b@x.com']
 *
 * $sup->release('user@example.com'); // address recovered / re-subscribed
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE email_suppressions (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     email         VARCHAR(255) NOT NULL,
 *     reason        VARCHAR(20)  NOT NULL,
 *     suppressed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (email)
 * );
 * ```
 */
final class EmailSuppression
{
    public const string REASON_BOUNCE      = 'bounce';
    public const string REASON_COMPLAINT   = 'complaint';
    public const string REASON_UNSUBSCRIBE = 'unsubscribe';
    public const string REASON_MANUAL      = 'manual';

    private const array REASONS = [
        self::REASON_BOUNCE,
        self::REASON_COMPLAINT,
        self::REASON_UNSUBSCRIBE,
        self::REASON_MANUAL,
    ];

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Add (or update the reason for) a suppressed address. Idempotent per email.
     *
     * @param  string $email  Address (normalised to lowercase).
     * @param  string $reason One of the REASON_* constants.
     * @throws \InvalidArgumentException on empty email or unknown reason.
     */
    public function suppress(string $email, string $reason = self::REASON_MANUAL): void
    {
        $email = $this->normalize($email);
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException("Unknown suppression reason: {$reason}");
        }

        DbUpsert::run(
            $this->db(),
            table:        'email_suppressions',
            data:         ['email' => $email, 'reason' => $reason],
            conflictCols: ['email'],
            updateCols:   ['reason'],
            updateExprs:  ['suppressed_at' => 'CURRENT_TIMESTAMP'],
        );
    }

    /**
     * Whether an address is suppressed (case-insensitive).
     */
    public function isSuppressed(string $email): bool
    {
        $email = $this->normalize($email);
        $stmt  = $this->db()->prepare('SELECT 1 FROM email_suppressions WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Return the suppression reason for an address, or null if not suppressed.
     */
    public function reasonFor(string $email): ?string
    {
        $email = $this->normalize($email);
        $stmt  = $this->db()->prepare('SELECT reason FROM email_suppressions WHERE email = ?');
        $stmt->execute([$email]);
        $reason = $stmt->fetchColumn();

        return $reason === false ? null : (string)$reason;
    }

    /**
     * Remove an address from the suppression list. No-op if absent.
     */
    public function release(string $email): void
    {
        $email = $this->normalize($email);
        $stmt  = $this->db()->prepare('DELETE FROM email_suppressions WHERE email = ?');
        $stmt->execute([$email]);
    }

    /**
     * Filter a list of addresses down to the deliverable (non-suppressed) ones.
     *
     * Preserves input order and the original casing of each kept address.
     * Performs a single query regardless of list size.
     *
     * @param  array<int,string> $emails Candidate addresses.
     * @return array<int,string>         Addresses that are not suppressed.
     */
    public function filter(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $normalised = [];
        foreach ($emails as $e) {
            $normalised[] = strtolower(trim($e));
        }
        $unique = array_values(array_unique(array_filter($normalised, static fn (string $e): bool => $e !== '')));
        if ($unique === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($unique), '?'));
        $stmt         = $this->db()->prepare(
            "SELECT email FROM email_suppressions WHERE email IN ({$placeholders})"
        );
        $stmt->execute($unique);

        $suppressed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $e) {
            $suppressed[(string)$e] = true;
        }

        $out = [];
        foreach ($emails as $original) {
            $key = strtolower(trim($original));
            if ($key !== '' && !isset($suppressed[$key])) {
                $out[] = $original;
            }
        }

        return $out;
    }

    /**
     * List suppressed addresses, optionally filtered by reason, newest first.
     *
     * @param  string|null $reason One of the REASON_* constants, or null for all.
     * @return array<int,array{email:string,reason:string,suppressed_at:string}>
     * @throws \InvalidArgumentException on unknown reason.
     */
    public function all(?string $reason = null): array
    {
        if ($reason !== null && !in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException("Unknown suppression reason: {$reason}");
        }

        if ($reason === null) {
            $stmt = $this->db()->query(
                'SELECT email, reason, suppressed_at FROM email_suppressions ORDER BY suppressed_at DESC, email ASC'
            );
            $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT email, reason, suppressed_at FROM email_suppressions WHERE reason = ? ORDER BY suppressed_at DESC, email ASC'
            );
            $stmt->execute([$reason]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $out = [];
        /** @var array<string,mixed> $row */
        foreach ($rows as $row) {
            $out[] = [
                'email'         => (string)$row['email'],
                'reason'        => (string)$row['reason'],
                'suppressed_at' => (string)$row['suppressed_at'],
            ];
        }

        return $out;
    }

    // ── private ───────────────────────────────────────────────────────────────

    private function normalize(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException('Email must not be empty.');
        }

        return $email;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
