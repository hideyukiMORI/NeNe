<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * EmailVerification — token-based email address verification with expiry.
 *
 * Issues a time-limited verification token for a (user_id, email) pair.
 * The SHA-256 hash of the token is stored; the raw token is only available
 * at issue time and is returned to the caller for delivery via email.
 *
 * Status lifecycle: `pending` → `verified` (or expired via TTL)
 *
 * ## Usage
 *
 * ```php
 * $ev = new EmailVerification($pdo);
 *
 * // Issue a token (send it to the user by email)
 * $token = $ev->issue('user-1', 'user@example.com');
 *
 * // Verify when the user clicks the link
 * $ok = $ev->verify($token);
 *
 * // Check whether the address is verified
 * $ev->isVerified('user-1', 'user@example.com'); // true
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE email_verifications (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     email       VARCHAR(255) NOT NULL,
 *     token_hash  VARCHAR(64)  NOT NULL,
 *     verified_at DATETIME     DEFAULT NULL,
 *     expires_at  DATETIME     NOT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (user_id, email)
 * );
 * ```
 */
final class EmailVerification
{
    private const DEFAULT_TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ) {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Issue a new verification token for (user_id, email).
     *
     * If a previous (unverified) entry exists for this pair, it is replaced.
     * Returns the raw hex token (64 chars); store only the hash.
     *
     * @return string  64-character hex token.
     * @throws \InvalidArgumentException if user_id or email is empty.
     */
    public function issue(string $userId, string $email): string
    {
        [$userId, $email] = $this->normalise($userId, $email);

        $raw       = bin2hex(random_bytes(32));
        $hash      = hash('sha256', $raw);
        $expiresAt = (new \DateTimeImmutable())->modify("+{$this->ttlSeconds} seconds");
        $expStr    = $expiresAt->format('Y-m-d H:i:s');

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT INTO email_verifications (user_id, email, token_hash, expires_at, verified_at)
                 VALUES (:uid, :email, :hash, :exp, NULL)
                 ON CONFLICT (user_id, email) DO UPDATE SET
                     token_hash  = :hash2,
                     expires_at  = :exp2,
                     verified_at = NULL,
                     created_at  = CURRENT_TIMESTAMP'
            )->execute([
                ':uid'   => $userId,
                ':email' => $email,
                ':hash'  => $hash,
                ':exp'   => $expStr,
                ':hash2' => $hash,
                ':exp2'  => $expStr,
            ]);
        } else {
            $db->prepare(
                'INSERT INTO email_verifications (user_id, email, token_hash, expires_at, verified_at)
                 VALUES (:uid, :email, :hash, :exp, NULL)
                 ON DUPLICATE KEY UPDATE
                     token_hash  = VALUES(token_hash),
                     expires_at  = VALUES(expires_at),
                     verified_at = NULL,
                     created_at  = CURRENT_TIMESTAMP'
            )->execute([':uid' => $userId, ':email' => $email, ':hash' => $hash, ':exp' => $expStr]);
        }

        return $raw;
    }

    /**
     * Verify a token.
     *
     * @return bool True if the token is valid, not expired, and not already verified.
     */
    public function verify(string $token): bool
    {
        $hash = hash('sha256', $token);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'UPDATE email_verifications
             SET verified_at = CURRENT_TIMESTAMP
             WHERE token_hash  = :hash
               AND verified_at IS NULL
               AND expires_at  > :now'
        );
        $stmt->execute([':hash' => $hash, ':now' => $now]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check whether the (user_id, email) pair has been verified.
     */
    public function isVerified(string $userId, string $email): bool
    {
        [$userId, $email] = $this->normalise($userId, $email);
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM email_verifications
             WHERE user_id = :uid AND email = :email AND verified_at IS NOT NULL'
        );
        $stmt->execute([':uid' => $userId, ':email' => $email]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Check whether a pending (unverified, unexpired) token exists.
     */
    public function isPending(string $userId, string $email): bool
    {
        [$userId, $email] = $this->normalise($userId, $email);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM email_verifications
             WHERE user_id = :uid AND email = :email
               AND verified_at IS NULL AND expires_at > :now'
        );
        $stmt->execute([':uid' => $userId, ':email' => $email, ':now' => $now]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get the expiry time of the current token for (user_id, email).
     *
     * Returns null if no token exists.
     */
    public function expiresAt(string $userId, string $email): ?\DateTimeImmutable
    {
        [$userId, $email] = $this->normalise($userId, $email);
        $stmt = $this->db()->prepare(
            'SELECT expires_at FROM email_verifications
             WHERE user_id = :uid AND email = :email LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':email' => $email]);
        $val = $stmt->fetchColumn();
        if ($val === false) {
            return null;
        }
        return new \DateTimeImmutable((string)$val);
    }

    /**
     * Revoke a verification (reset to unverified, remove token).
     *
     * Useful when the user changes their email address.
     *
     * @return bool True if an entry was removed.
     */
    public function revoke(string $userId, string $email): bool
    {
        [$userId, $email] = $this->normalise($userId, $email);
        $stmt = $this->db()->prepare(
            'DELETE FROM email_verifications WHERE user_id = :uid AND email = :email'
        );
        $stmt->execute([':uid' => $userId, ':email' => $email]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Purge all expired, unverified tokens.
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'DELETE FROM email_verifications WHERE verified_at IS NULL AND expires_at <= :now'
        );
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $userId, string $email): array
    {
        $userId = trim($userId);
        $email  = trim($email);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($email === '') {
            throw new \InvalidArgumentException('email must not be empty.');
        }
        return [$userId, $email];
    }
}
