<?php

declare(strict_types=1);

namespace Nene\Kit;

/**
 * Cryptographic helpers for password-reset token workflows.
 *
 * The raw token is a 256-bit random hex string returned to the user
 * (e.g., via email link). Only the SHA-256 hash is stored in the database.
 * If the DB is breached, the attacker obtains hashes of random 256-bit
 * values — computationally infeasible to reverse.
 *
 * ## Usage
 *
 * Request reset:
 *   $raw  = PasswordResetToken::generate();
 *   $hash = PasswordResetToken::hash($raw);
 *   // store $hash in DB; send $raw to user's email
 *
 * Complete reset (token from URL/body):
 *   $row = $this->resetMapper->findByHash(PasswordResetToken::hash($rawToken));
 *   if ($row === null) { 404 }
 *   if (PasswordResetToken::isExpired($row->expires_at)) { 410 }
 *   if ($row->used_at !== null) { 409 }
 *   // apply new password, mark token used
 */
final class PasswordResetToken
{
    /**
     * Generate a cryptographically secure 256-bit (64 hex character) raw token.
     *
     * Only this value should be sent to the user (e.g. in an email link).
     * Never store the raw token in the database.
     *
     * @return string 64-character lowercase hex string.
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash a raw token for database storage.
     *
     * Uses SHA-256 (sufficient for random high-entropy tokens; bcrypt/Argon2
     * is reserved for user-chosen passwords which have low entropy).
     *
     * @param string $rawToken Raw token from generate().
     *
     * @return string 64-character hex SHA-256 hash.
     */
    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Verify a raw token against a stored hash using constant-time comparison.
     *
     * Prefer this over `self::hash($raw) === $stored` to prevent timing attacks,
     * even though the token is random (defence in depth).
     *
     * @param string $rawToken   Raw token from the user request.
     * @param string $storedHash Hash value stored in the database.
     *
     * @return bool True if the token matches the stored hash.
     */
    public static function verify(string $rawToken, string $storedHash): bool
    {
        return hash_equals(self::hash($rawToken), $storedHash);
    }

    /**
     * Check whether a token has expired.
     *
     * @param string|null $expiresAt Expiry datetime string (Y-m-d H:i:s), or null = never expires.
     * @param string|null $now       Current datetime for testing; null = current time.
     *
     * @return bool True if the token has expired.
     */
    public static function isExpired(?string $expiresAt, ?string $now = null): bool
    {
        if ($expiresAt === null) {
            return false;
        }
        $current = $now ?? date('Y-m-d H:i:s');
        return $current >= $expiresAt;
    }

    /**
     * Calculate an expiry datetime string from now.
     *
     * @param int         $minutes Minutes from now until expiry. Default: 60.
     * @param string|null $now     Current datetime for testing; null = current time.
     *
     * @return string Expiry datetime in Y-m-d H:i:s format.
     */
    public static function expiresAt(int $minutes = 60, ?string $now = null): string
    {
        $base = $now !== null ? strtotime($now) : time();
        return date('Y-m-d H:i:s', (int)$base + ($minutes * 60));
    }
}
