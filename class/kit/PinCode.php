<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * PinCode — short PIN / OTP issuance with attempt limiting and expiry.
 *
 * Issues numeric PINs (4–8 digits) for a user+purpose (e.g. "phone_verify",
 * "login_2fa"). The PIN is stored as a SHA-256 hash. The raw PIN is returned
 * once at issuance; the caller is responsible for delivering it.
 *
 * After `maxAttempts` failed verifications the PIN is locked and cannot be
 * used again. Only one active PIN per user+purpose is allowed at a time
 * (issuing a new PIN invalidates previous ones).
 *
 * ## Usage
 *
 * ```php
 * $pc = new PinCode($pdo);
 *
 * // Issue a 6-digit PIN valid for 10 minutes
 * $raw = $pc->issue('user-1', 'phone_verify', digits: 6, ttlSeconds: 600);
 * // → deliver $raw to the user via SMS / email
 *
 * // Verify
 * if (!$pc->verify('user-1', 'phone_verify', $raw)) {
 *     // wrong PIN or expired
 * }
 *
 * // Consume (verify + mark used in one step)
 * $ok = $pc->consume('user-1', 'phone_verify', $raw);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE pin_codes (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id      VARCHAR(255) NOT NULL,
 *     purpose      VARCHAR(100) NOT NULL,
 *     pin_hash     VARCHAR(64)  NOT NULL,
 *     attempts     INTEGER      NOT NULL DEFAULT 0,
 *     max_attempts INTEGER      NOT NULL DEFAULT 5,
 *     used         TINYINT(1)   NOT NULL DEFAULT 0,
 *     expires_at   DATETIME     NOT NULL,
 *     issued_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class PinCode
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Issue a new PIN for a user+purpose.
     *
     * Any previous PIN for the same user+purpose is invalidated (marked used).
     *
     * @param  int $digits      Number of digits (4–8).
     * @param  int $ttlSeconds  Validity window in seconds (default 600 = 10 min).
     * @param  int $maxAttempts Maximum failed attempts before locking (default 5).
     * @return string The raw PIN digits.
     * @throws \InvalidArgumentException on empty inputs or out-of-range digits.
     */
    public function issue(
        string $userId,
        string $purpose,
        int $digits = 6,
        int $ttlSeconds = 600,
        int $maxAttempts = 5
    ): string {
        [$userId, $purpose] = $this->validateInputs($userId, $purpose);
        if ($digits < 4 || $digits > 8) {
            throw new \InvalidArgumentException('digits must be between 4 and 8.');
        }

        // Invalidate previous PINs for this user+purpose
        $this->db()->prepare(
            'UPDATE pin_codes SET used = 1 WHERE user_id = :uid AND purpose = :purpose AND used = 0'
        )->execute([':uid' => $userId, ':purpose' => $purpose]);

        $rawPin    = str_pad((string)random_int(0, (int)(10 ** $digits) - 1), $digits, '0', STR_PAD_LEFT);
        $expiresAt = (new \DateTimeImmutable())->modify("+{$ttlSeconds} seconds")->format('Y-m-d H:i:s');

        $this->db()->prepare(
            'INSERT INTO pin_codes (user_id, purpose, pin_hash, max_attempts, expires_at)
             VALUES (:uid, :purpose, :hash, :max, :exp)'
        )->execute([
            ':uid'     => $userId,
            ':purpose' => $purpose,
            ':hash'    => hash('sha256', $rawPin),
            ':max'     => $maxAttempts,
            ':exp'     => $expiresAt,
        ]);

        return $rawPin;
    }

    /**
     * Verify a PIN without consuming it.
     *
     * Increments the attempt counter on failure.
     * Returns true only if the PIN is valid, not expired, not used, and not locked.
     */
    public function verify(string $userId, string $purpose, string $rawPin): bool
    {
        return $this->checkPin($userId, $purpose, $rawPin, consume: false);
    }

    /**
     * Verify and consume (mark as used) a PIN in one step.
     *
     * Increments the attempt counter on failure.
     */
    public function consume(string $userId, string $purpose, string $rawPin): bool
    {
        return $this->checkPin($userId, $purpose, $rawPin, consume: true);
    }

    /**
     * Explicitly invalidate all active PINs for a user+purpose.
     *
     * @return int Number of rows updated.
     */
    public function invalidate(string $userId, string $purpose): int
    {
        [$userId, $purpose] = $this->validateInputs($userId, $purpose);
        $stmt = $this->db()->prepare(
            'UPDATE pin_codes SET used = 1 WHERE user_id = :uid AND purpose = :purpose AND used = 0'
        );
        $stmt->execute([':uid' => $userId, ':purpose' => $purpose]);
        return $stmt->rowCount();
    }

    /**
     * Delete expired PINs (maintenance).
     *
     * @return int Number of rows deleted.
     */
    public function purgeExpired(): int
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare('DELETE FROM pin_codes WHERE expires_at <= :now');
        $stmt->execute([':now' => $now]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function checkPin(string $userId, string $purpose, string $rawPin, bool $consume): bool
    {
        [$userId, $purpose] = $this->validateInputs($userId, $purpose);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db()->prepare(
            'SELECT id, pin_hash, attempts, max_attempts
             FROM pin_codes
             WHERE user_id = :uid AND purpose = :purpose AND used = 0 AND expires_at > :now
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':purpose' => $purpose, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        $id          = (int)$row['id'];
        $attempts    = (int)$row['attempts'];
        $maxAttempts = (int)$row['max_attempts'];

        // Locked?
        if ($attempts >= $maxAttempts) {
            return false;
        }

        if (!hash_equals((string)$row['pin_hash'], hash('sha256', $rawPin))) {
            // Wrong PIN — increment attempts
            $this->db()->prepare(
                'UPDATE pin_codes SET attempts = attempts + 1 WHERE id = :id'
            )->execute([':id' => $id]);
            return false;
        }

        // Correct PIN
        if ($consume) {
            $this->db()->prepare('UPDATE pin_codes SET used = 1 WHERE id = :id')->execute([':id' => $id]);
        }

        return true;
    }

    /**
     * @return array{string, string}
     */
    private function validateInputs(string $userId, string $purpose): array
    {
        $userId  = trim($userId);
        $purpose = trim($purpose);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($purpose === '') {
            throw new \InvalidArgumentException('purpose must not be empty.');
        }
        return [$userId, $purpose];
    }
}
