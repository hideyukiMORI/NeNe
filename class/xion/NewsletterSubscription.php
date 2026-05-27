<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Newsletter / mailing-list subscription manager with double opt-in support.
 *
 * Each subscription tracks an email address, a list name, and a status:
 *  - `pending`     — subscribed but confirmation not yet clicked (double opt-in)
 *  - `confirmed`   — active subscriber
 *  - `unsubscribed`— opted out (row kept for audit)
 *
 * Confirmation tokens are 64 hex chars (256-bit random, SHA-256 stored).
 *
 * ## Usage
 *
 * ```php
 * $nl = new NewsletterSubscription($pdo);
 *
 * // Subscribe (double opt-in)
 * $rawToken = $nl->subscribe('user@example.com', 'weekly');
 * // … email $rawToken in the confirmation link …
 *
 * // Confirm
 * $ok = $nl->confirm($rawToken);
 *
 * // Direct confirm (no double opt-in required)
 * $nl->subscribe('user@example.com', 'weekly', confirm: true);
 *
 * // Unsubscribe
 * $nl->unsubscribe('user@example.com', 'weekly');
 *
 * // Status
 * $status = $nl->status('user@example.com', 'weekly'); // 'confirmed'|'pending'|'unsubscribed'|null
 *
 * // List confirmed subscribers for a list
 * $emails = $nl->listConfirmed('weekly');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE newsletter_subscriptions (
 *     id              INTEGER PRIMARY KEY AUTOINCREMENT,
 *     email           VARCHAR(255) NOT NULL,
 *     list_name       VARCHAR(100) NOT NULL,
 *     status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     confirm_hash    VARCHAR(64)  DEFAULT NULL,
 *     confirmed_at    DATETIME     DEFAULT NULL,
 *     unsubscribed_at DATETIME     DEFAULT NULL,
 *     created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (email, list_name)
 * );
 * ```
 */
final class NewsletterSubscription
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Subscribe an email address to a list.
     *
     * If the email is already confirmed, this is a no-op and returns null.
     * If previously unsubscribed, the subscription is reset to pending.
     * If `$confirm` is true, the status is immediately set to `confirmed`.
     *
     * @param  string $email     Email address (case-insensitive).
     * @param  string $listName  List identifier.
     * @param  bool   $confirm   If true, bypass double opt-in and confirm immediately.
     * @return string|null       Raw confirmation token (null when already confirmed or $confirm = true).
     * @throws \InvalidArgumentException if email is invalid or list name is empty.
     */
    public function subscribe(string $email, string $listName, bool $confirm = false): ?string
    {
        $email    = $this->normaliseEmail($email);
        $listName = $this->normaliseList($listName);
        $db       = $this->db();

        $existing = $this->fetchRow($email, $listName);

        if ($existing !== null && $existing['status'] === 'confirmed') {
            return null; // already confirmed — no change
        }

        if ($confirm) {
            // Upsert directly to confirmed
            $this->upsert($db, $email, $listName, 'confirmed', null, 'CURRENT_TIMESTAMP', null);
            return null;
        }

        // Generate confirmation token
        $rawToken = bin2hex(random_bytes(32)); // 64 hex chars
        $hash     = hash('sha256', $rawToken);

        $this->upsert($db, $email, $listName, 'pending', $hash, null, null);
        return $rawToken;
    }

    /**
     * Confirm a subscription using the raw token from the confirmation email.
     *
     * @param  string $rawToken Raw token (as returned by subscribe()).
     * @return bool   True if confirmed successfully; false if token not found / already used.
     */
    public function confirm(string $rawToken): bool
    {
        $hash = hash('sha256', $rawToken);
        $stmt = $this->db()->prepare(
            "UPDATE newsletter_subscriptions
             SET status = 'confirmed', confirmed_at = CURRENT_TIMESTAMP,
                 confirm_hash = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE confirm_hash = :hash AND status = 'pending'"
        );
        $stmt->execute([':hash' => $hash]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Unsubscribe an email from a list.
     *
     * @return bool True if the subscription existed (regardless of previous status).
     */
    public function unsubscribe(string $email, string $listName): bool
    {
        $email    = $this->normaliseEmail($email);
        $listName = $this->normaliseList($listName);

        $stmt = $this->db()->prepare(
            "UPDATE newsletter_subscriptions
             SET status = 'unsubscribed', unsubscribed_at = CURRENT_TIMESTAMP,
                 confirm_hash = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE email = :email AND list_name = :list_name AND status != 'unsubscribed'"
        );
        $stmt->execute([':email' => $email, ':list_name' => $listName]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get the current subscription status for an email+list pair.
     *
     * @return 'pending'|'confirmed'|'unsubscribed'|null
     */
    public function status(string $email, string $listName): ?string
    {
        $email    = $this->normaliseEmail($email);
        $listName = $this->normaliseList($listName);
        $row      = $this->fetchRow($email, $listName);
        return $row !== null ? $row['status'] : null;
    }

    /**
     * List confirmed subscriber emails for a given list.
     *
     * @return list<string>
     */
    public function listConfirmed(string $listName): array
    {
        $listName = $this->normaliseList($listName);
        $stmt     = $this->db()->prepare(
            "SELECT email FROM newsletter_subscriptions
             WHERE list_name = :list_name AND status = 'confirmed'
             ORDER BY confirmed_at ASC"
        );
        $stmt->execute([':list_name' => $listName]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'email');
    }

    /**
     * Re-subscribe an unsubscribed email (resets to pending; returns new token).
     *
     * Convenience wrapper — identical to subscribe() since subscribe() resets unsubscribed rows.
     */
    public function resubscribe(string $email, string $listName): ?string
    {
        return $this->subscribe($email, $listName);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $email, string $listName): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM newsletter_subscriptions WHERE email = :email AND list_name = :list_name LIMIT 1'
        );
        $stmt->execute([':email' => $email, ':list_name' => $listName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function upsert(
        PDO $db,
        string $email,
        string $listName,
        string $status,
        ?string $confirmHash,
        ?string $confirmedAt,
        ?string $unsubscribedAt
    ): void {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'INSERT OR REPLACE INTO newsletter_subscriptions
                    (email, list_name, status, confirm_hash, confirmed_at, unsubscribed_at, updated_at)
                 VALUES (:email, :list_name, :status, :confirm_hash, :confirmed_at, :unsubscribed_at, CURRENT_TIMESTAMP)'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT INTO newsletter_subscriptions
                    (email, list_name, status, confirm_hash, confirmed_at, unsubscribed_at, updated_at)
                 VALUES (:email, :list_name, :status, :confirm_hash, :confirmed_at, :unsubscribed_at, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    confirm_hash = VALUES(confirm_hash),
                    confirmed_at = VALUES(confirmed_at),
                    unsubscribed_at = VALUES(unsubscribed_at),
                    updated_at = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([
            ':email'           => $email,
            ':list_name'       => $listName,
            ':status'          => $status,
            ':confirm_hash'    => $confirmHash,
            ':confirmed_at'    => $confirmedAt,
            ':unsubscribed_at' => $unsubscribedAt,
        ]);
    }

    private function normaliseEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address: '{$email}'.");
        }
        return $email;
    }

    private function normaliseList(string $listName): string
    {
        $listName = trim($listName);
        if ($listName === '') {
            throw new \InvalidArgumentException('List name must not be empty.');
        }
        return $listName;
    }
}
