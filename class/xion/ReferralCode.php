<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Referral code system — generate invite links and track conversions.
 *
 * Each user gets one referral code. When a new user signs up using a code,
 * a conversion row is inserted. The referrer can then be rewarded at the
 * application layer (e.g. via PointLedger).
 *
 * ## Usage
 *
 * ```php
 * $ref = new ReferralCode($pdo);
 *
 * // Assign or get a code for a user (idempotent)
 * $code = $ref->getOrCreate('user-1');  // e.g. 'ABCD1234'
 *
 * // Get referrer by code
 * $referrer = $ref->findByCode('ABCD1234');  // ['user_id' => 'user-1', ...]
 *
 * // Record a conversion (new user signs up via the code)
 * $ref->convert('ABCD1234', 'new-user-99');
 *
 * // List conversions for a referrer
 * $conversions = $ref->conversions('user-1');
 *
 * // Count
 * $ref->conversionCount('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE referral_codes (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL UNIQUE,
 *     code        VARCHAR(20)  NOT NULL UNIQUE,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE referral_conversions (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     code          VARCHAR(20)  NOT NULL,
 *     referred_user VARCHAR(255) NOT NULL UNIQUE,
 *     converted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ReferralCode
{
    /** Characters used in generated codes — no ambiguous 0/O, 1/I/L */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Get the referral code for a user, creating one if it doesn't exist.
     *
     * Idempotent — calling this multiple times always returns the same code.
     *
     * @return string The user's referral code.
     */
    public function getOrCreate(string $userId): string
    {
        $existing = $this->findByUser($userId);
        if ($existing !== null) {
            return $existing['code'];
        }

        // Generate a collision-resistant random code
        $code = $this->generateCode();

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO referral_codes (user_id, code) VALUES (:user, :code)'
            : 'INSERT IGNORE INTO referral_codes (user_id, code) VALUES (:user, :code)';

        $db->prepare($sql)->execute([':user' => $userId, ':code' => $code]);

        // Re-fetch in case of race
        $row = $this->findByUser($userId);
        return $row !== null ? $row['code'] : $code;
    }

    /**
     * Find referral code record by the raw code string.
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM referral_codes WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => strtoupper(trim($code))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Find referral code record by user ID.
     *
     * @return array<string, mixed>|null
     */
    public function findByUser(string $userId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM referral_codes WHERE user_id = :user LIMIT 1'
        );
        $stmt->execute([':user' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Record a conversion: a new user (`$referredUserId`) signed up via `$code`.
     *
     * Idempotent: a user can only be referred once (UNIQUE referred_user).
     *
     * @throws \InvalidArgumentException if the code does not exist.
     * @return bool True if the conversion was newly recorded.
     */
    public function convert(string $code, string $referredUserId): bool
    {
        $code = strtoupper(trim($code));

        // Verify code exists
        if ($this->findByCode($code) === null) {
            throw new \InvalidArgumentException("Referral code '{$code}' does not exist.");
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO referral_conversions (code, referred_user) VALUES (:code, :user)'
            : 'INSERT IGNORE INTO referral_conversions (code, referred_user) VALUES (:code, :user)';

        $stmt = $db->prepare($sql);
        $stmt->execute([':code' => $code, ':user' => $referredUserId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all conversions for a user (via their referral code).
     *
     * @return list<array<string, mixed>>
     */
    public function conversions(string $userId): array
    {
        $row = $this->findByUser($userId);
        if ($row === null) {
            return [];
        }

        $stmt = $this->db()->prepare(
            'SELECT * FROM referral_conversions WHERE code = :code ORDER BY converted_at ASC'
        );
        $stmt->execute([':code' => $row['code']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count the number of conversions for a user.
     */
    public function conversionCount(string $userId): int
    {
        $row = $this->findByUser($userId);
        if ($row === null) {
            return 0;
        }

        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM referral_conversions WHERE code = :code'
        );
        $stmt->execute([':code' => $row['code']]);
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function generateCode(): string
    {
        $alphabet = self::ALPHABET;
        $len      = strlen($alphabet);
        $code     = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $len - 1)];
        }
        return $code;
    }
}
