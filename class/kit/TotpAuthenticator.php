<?php

declare(strict_types=1);

namespace Nene\Kit;

/**
 * TOTP (Time-based One-Time Password) authenticator — RFC 6238 / Google Authenticator compatible.
 *
 * Generates base32-encoded secrets, produces/verifies 6-digit TOTP codes,
 * and generates one-time backup codes for account recovery.
 *
 * This is a zero-dependency, pure-PHP implementation.
 * The secret is stored by the application; this class performs no DB I/O.
 *
 * ## Usage
 *
 * ```php
 * $totp = new TotpAuthenticator();
 *
 * // Enrolment
 * $secret = $totp->generateSecret();  // store this per-user
 * $uri    = $totp->otpauthUri($secret, 'user@example.com', 'MyApp');  // for QR code
 *
 * // Verification
 * $code = $_POST['totp_code'];
 * if ($totp->verifyCode($secret, $code)) {
 *     // 2FA passed
 * }
 *
 * // Backup codes
 * $codes = $totp->generateBackupCodes(8);
 * // store hashed; verify with verifyBackupCode()
 * ```
 */
final class TotpAuthenticator
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const CODE_DIGITS  = 6;
    private const TIME_STEP    = 30;   // seconds
    private const WINDOW       = 1;    // ±1 step tolerance

    /**
     * Generate a cryptographically random base32-encoded secret.
     *
     * @param int $length Number of base32 characters (multiple of 8; default 32 = 160 bits).
     */
    public function generateSecret(int $length = 32): string
    {
        $chars  = self::BASE32_CHARS;
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Generate the current TOTP code for a secret (for testing only — not for production use).
     *
     * @param string   $secret Base32-encoded secret.
     * @param int|null $time   Unix timestamp (null = current time).
     */
    public function generateCode(string $secret, ?int $time = null): string
    {
        $counter = $this->timeCounter($time ?? time());
        return $this->hotp($secret, $counter);
    }

    /**
     * Verify a TOTP code against a secret, with ±1 time-step tolerance.
     *
     * @param string $secret  Base32-encoded secret.
     * @param string $code    6-digit code to verify.
     * @param int|null $time  Unix timestamp (null = current time).
     */
    public function verifyCode(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (strlen($code) !== self::CODE_DIGITS || !ctype_digit($code)) {
            return false;
        }

        $now = $time ?? time();
        for ($delta = -self::WINDOW; $delta <= self::WINDOW; $delta++) {
            $counter  = $this->timeCounter($now + $delta * self::TIME_STEP);
            $expected = $this->hotp($secret, $counter);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build an otpauth:// URI for QR code generation.
     *
     * @param string $secret  Base32-encoded secret.
     * @param string $account Account label (e.g. 'user@example.com').
     * @param string $issuer  Application name (e.g. 'MyApp').
     */
    public function otpauthUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'
             . rawurlencode($issuer . ':' . $account)
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1'
             . '&digits=' . self::CODE_DIGITS
             . '&period=' . self::TIME_STEP;
    }

    /**
     * Generate one-time backup recovery codes.
     *
     * Each code is a random hex string in the format XXXX-XXXX-XXXX.
     * Store the SHA-256 hash; compare by hashing the user-provided code.
     *
     * @param  int          $count Number of codes to generate (1–20).
     * @return list<string>
     */
    public function generateBackupCodes(int $count = 8): array
    {
        $count = max(1, min(20, $count));
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw     = bin2hex(random_bytes(6));  // 12 hex chars
            $codes[] = strtoupper(substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4));
        }
        return $codes;
    }

    /**
     * Hash a backup code for safe storage.
     *
     * @param string $code Raw backup code (e.g. 'ABCD-1234-EF56').
     */
    public function hashBackupCode(string $code): string
    {
        return hash('sha256', strtoupper(str_replace('-', '', $code)));
    }

    /**
     * Verify a raw backup code against its stored hash.
     *
     * @param string $code       Raw code provided by the user.
     * @param string $storedHash Hash from hashBackupCode().
     */
    public function verifyBackupCode(string $code, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hashBackupCode($code));
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function timeCounter(int $time): int
    {
        return (int)floor($time / self::TIME_STEP);
    }

    /**
     * HMAC-based OTP (RFC 4226).
     *
     * @param string $secret  Base32-encoded secret.
     * @param int    $counter Counter value.
     */
    private function hotp(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        // Pack counter as 8-byte big-endian
        $msg  = pack('N*', 0) . pack('N*', $counter);
        $hmac = hash_hmac('sha1', $msg, $key, true);

        // Dynamic truncation
        $offset = ord($hmac[19]) & 0x0F;
        $code   = (
            ((ord($hmac[$offset]) & 0x7F) << 24)
          | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
          | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
          |  (ord($hmac[$offset + 3]) & 0xFF)
        ) % (10 ** self::CODE_DIGITS);

        return str_pad((string)$code, self::CODE_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Decode a base32-encoded string to binary.
     */
    private function base32Decode(string $input): string
    {
        $input    = strtoupper(rtrim($input, '='));
        $charMap  = array_flip(str_split(self::BASE32_CHARS));
        $bits     = '';

        foreach (str_split($input) as $char) {
            if (!isset($charMap[$char])) {
                continue;
            }
            $bits .= str_pad(decbin($charMap[$char]), 5, '0', STR_PAD_LEFT);
        }

        $bytes  = '';
        $chunks = str_split($bits, 8);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
