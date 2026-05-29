<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * MagicLink — email-based passwordless authentication tokens.
 *
 * Generates single-use, time-limited tokens sent to a user's email address.
 * Consuming a token marks it as used; it cannot be reused. Expired or used
 * tokens are ignored by consume().
 *
 * Raw tokens are returned once on generation and never stored in plain text.
 * The DB stores a SHA-256 hash.
 *
 * ## Usage
 *
 * ```php
 * $ml = new MagicLink($pdo);
 *
 * // Generate and send
 * $raw = $ml->generate('user@example.com', ttlSeconds: 900); // 15 min
 * // → send email with link: /auth/magic?token={$raw}
 *
 * // On click: consume
 * $record = $ml->consume($raw);
 * if ($record !== null) {
 *     // log in the user identified by $record['email']
 * }
 *
 * // Maintenance
 * $ml->purgeExpired();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE magic_links (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     email      VARCHAR(255) NOT NULL,
 *     token_hash VARCHAR(64)  NOT NULL UNIQUE,
 *     used_at    DATETIME     DEFAULT NULL,
 *     expires_at DATETIME     NOT NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class MagicLink
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Generate a magic-link token for the given email address.
     *
     * @param int $ttlSeconds Time-to-live in seconds (default 15 minutes).
     * @return string The raw token to embed in the link (not stored).
     * @throws \InvalidArgumentException if email is empty.
     */
    public function generate(string $email, int $ttlSeconds = 900): string
    {
        $email = trim($email);
        if ($email === '') {
            throw new \InvalidArgumentException('email must not be empty.');
        }
        $rawToken  = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $rawToken);
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

        $this->db()->prepare(
            'INSERT INTO magic_links (email, token_hash, expires_at)
             VALUES (:email, :hash, :exp)'
        )->execute([':email' => $email, ':hash' => $hash, ':exp' => $expiresAt]);

        return $rawToken;
    }

    /**
     * Consume a magic-link token.
     *
     * Returns the record and marks the token as used, or returns null if the
     * token is invalid, expired, or already used.
     *
     * @return array<string,mixed>|null
     */
    public function consume(string $rawToken): ?array
    {
        $hash = hash('sha256', $rawToken);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $db   = $this->db();

        $stmt = $db->prepare(
            'SELECT id, email, expires_at FROM magic_links
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([':hash' => $hash, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $db->prepare(
            'UPDATE magic_links SET used_at = CURRENT_TIMESTAMP WHERE id = :id'
        )->execute([':id' => $row['id']]);

        return $row;
    }

    /**
     * Check whether a raw token is valid (not expired, not used).
     */
    public function isValid(string $rawToken): bool
    {
        $hash = hash('sha256', $rawToken);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM magic_links
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > :now'
        );
        $stmt->execute([':hash' => $hash, ':now' => $now]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Count pending (unused, non-expired) tokens for an email.
     */
    public function pendingCount(string $email): int
    {
        $email = trim($email);
        $now   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt  = $this->db()->prepare(
            'SELECT COUNT(*) FROM magic_links
             WHERE email = :email AND used_at IS NULL AND expires_at > :now'
        );
        $stmt->execute([':email' => $email, ':now' => $now]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete expired and used tokens.
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'DELETE FROM magic_links WHERE expires_at <= :now OR used_at IS NOT NULL'
        );
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
