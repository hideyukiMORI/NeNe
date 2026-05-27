<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * OtpChallenge — one-time password (OTP) challenge issuance and verification.
 *
 * Issues short numeric or alphanumeric OTPs for email/SMS verification,
 * step-up authentication, or any context requiring a single-use code.
 * Codes are SHA-256 hashed at rest; attempts are limited to prevent brute-force.
 *
 * Distinct from:
 * - `PinCode`            (admin-set user PIN codes)
 * - `MagicLink`          (tokenized login links)
 * - `TotpAuthenticator`  (HMAC time-based OTP / RFC 6238)
 * - `TwoFactorBackupCode` (static recovery codes for 2FA)
 *
 * ## Usage
 *
 * ```php
 * $otp = new OtpChallenge($pdo);
 *
 * [$id, $code] = $otp->issue('user-1', 'email-verify', 6, 900);
 * // send $code to user via email/SMS
 *
 * if ($otp->verify($id, $userInput)) {
 *     // valid
 * }
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE otp_challenges (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     purpose    VARCHAR(100) NOT NULL,
 *     code_hash  VARCHAR(64)  NOT NULL,
 *     expires_at DATETIME     NOT NULL,
 *     attempts   INTEGER      NOT NULL DEFAULT 0,
 *     used       INTEGER      NOT NULL DEFAULT 0,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class OtpChallenge
{
    public const DEFAULT_LENGTH      = 6;
    public const DEFAULT_TTL_SECONDS = 600;
    public const DEFAULT_MAX_ATTEMPTS = 5;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Issue a new OTP challenge.
     *
     * Any previous active challenges for the same user+purpose are invalidated.
     *
     * @param  int $length     Code length in characters (default 6).
     * @param  int $ttlSeconds Seconds until expiry (default 600).
     * @return array{0: int, 1: string}  [challengeId, plaintext code]
     * @throws \InvalidArgumentException on empty userId or purpose.
     */
    public function issue(
        string $userId,
        string $purpose,
        int $length = self::DEFAULT_LENGTH,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS
    ): array {
        $userId  = trim($userId);
        $purpose = trim($purpose);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($purpose === '') {
            throw new \InvalidArgumentException('purpose must not be empty.');
        }
        if ($length < 4) {
            throw new \InvalidArgumentException('length must be at least 4.');
        }

        // Invalidate any previous active challenges for this user+purpose
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db()->prepare(
            'UPDATE otp_challenges SET used = 1
             WHERE user_id = :uid AND purpose = :purpose AND used = 0 AND expires_at > :now'
        )->execute([':uid' => $userId, ':purpose' => $purpose, ':now' => $now]);

        $code      = $this->generateCode($length);
        $codeHash  = hash('sha256', $code);
        $expiresAt = (new \DateTimeImmutable())
            ->modify("+{$ttlSeconds} seconds")
            ->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'INSERT INTO otp_challenges (user_id, purpose, code_hash, expires_at, attempts, used, created_at)
             VALUES (:uid, :purpose, :hash, :expires, 0, 0, :now)'
        );
        $stmt->execute([
            ':uid'     => $userId,
            ':purpose' => $purpose,
            ':hash'    => $codeHash,
            ':expires' => $expiresAt,
            ':now'     => $now,
        ]);
        return [(int)$this->db()->lastInsertId(), $code];
    }

    /**
     * Retrieve a challenge record by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM otp_challenges WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Verify a code against a challenge.
     *
     * Increments attempt count. Marks challenge as used if code matches.
     *
     * @param  int $maxAttempts Maximum allowed incorrect attempts before the challenge is voided.
     * @return bool True if the code is correct and the challenge is active.
     */
    public function verify(
        int $id,
        string $code,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ): bool {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $row  = $this->get($id);

        if ($row === null) {
            return false;
        }
        if ((int)$row['used'] !== 0) {
            return false;
        }
        if ($row['expires_at'] <= $now) {
            return false;
        }
        if ((int)$row['attempts'] >= $maxAttempts) {
            return false;
        }

        $match = hash_equals((string)$row['code_hash'], hash('sha256', $code));

        // Increment attempt count
        $this->db()->prepare(
            'UPDATE otp_challenges SET attempts = attempts + 1 WHERE id = :id'
        )->execute([':id' => $id]);

        if ($match) {
            // Mark as used
            $this->db()->prepare(
                'UPDATE otp_challenges SET used = 1 WHERE id = :id'
            )->execute([':id' => $id]);
            return true;
        }
        return false;
    }

    /**
     * Whether a challenge is still valid (not used, not expired, under attempt limit).
     *
     * @param int $maxAttempts
     */
    public function isValid(
        int $id,
        int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS
    ): bool {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $row = $this->get($id);
        if ($row === null) {
            return false;
        }
        return (int)$row['used'] === 0
            && $row['expires_at'] > $now
            && (int)$row['attempts'] < $maxAttempts;
    }

    /**
     * Invalidate a challenge manually (mark as used).
     *
     * @return bool True if found and not already used.
     */
    public function invalidate(int $id): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE otp_challenges SET used = 1 WHERE id = :id AND used = 0'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete expired or used challenges older than a cutoff.
     *
     * @return int Rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM otp_challenges
             WHERE (used = 1 OR expires_at < :now) AND created_at < :cutoff'
        );
        $stmt->execute([':now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), ':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function generateCode(int $length): string
    {
        $chars  = '0123456789';
        $code   = '';
        $max    = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        return $code;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
