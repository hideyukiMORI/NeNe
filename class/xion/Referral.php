<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Referral — referral code generation, attribution, and conversion tracking.
 *
 * Each user gets one referral code. When a referred user signs up and
 * later converts, the referrer is credited. Prevents self-referral and
 * double-attribution.
 *
 * ## Usage
 *
 * ```php
 * $ref = new Referral($pdo);
 *
 * // Generate or get existing code for a user
 * $code = $ref->codeFor('user-1');
 *
 * // Record that user-2 was referred by this code
 * $ref->attribute('user-2', $code);
 *
 * // Mark conversion (user-2 completed a purchase, etc.)
 * $ref->convert('user-2');
 *
 * // Stats for the referrer
 * $ref->stats('user-1'); // ['referrals' => 1, 'conversions' => 1]
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE referral_codes (
 *     user_id    VARCHAR(255) NOT NULL PRIMARY KEY,
 *     code       VARCHAR(20)  NOT NULL UNIQUE,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE referrals (
 *     id            INTEGER PRIMARY KEY AUTOINCREMENT,
 *     referred_id   VARCHAR(255) NOT NULL UNIQUE,
 *     referrer_id   VARCHAR(255) NOT NULL,
 *     converted     TINYINT(1)   NOT NULL DEFAULT 0,
 *     converted_at  DATETIME     DEFAULT NULL,
 *     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class Referral
{
    private const CODE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const CODE_LEN   = 8;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Get or generate the referral code for a user.
     *
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function codeFor(string $userId): string
    {
        $userId = $this->validateUserId($userId);
        $db     = $this->db();

        // Return existing code
        $stmt = $db->prepare('SELECT code FROM referral_codes WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (string)$existing;
        }

        // Generate unique code
        do {
            $code   = $this->generateCode();
            $exists = $db->prepare('SELECT COUNT(*) FROM referral_codes WHERE code = :code');
            $exists->execute([':code' => $code]);
        } while ((int)$exists->fetchColumn() > 0);

        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $db->prepare(
                'INSERT OR IGNORE INTO referral_codes (user_id, code) VALUES (:uid, :code)'
            )->execute([':uid' => $userId, ':code' => $code]);
        } else {
            $db->prepare(
                'INSERT IGNORE INTO referral_codes (user_id, code) VALUES (:uid, :code)'
            )->execute([':uid' => $userId, ':code' => $code]);
        }

        // Re-read in case of race condition
        $stmt->execute([':uid' => $userId]);
        $stmt2 = $db->prepare('SELECT code FROM referral_codes WHERE user_id = :uid LIMIT 1');
        $stmt2->execute([':uid' => $userId]);
        return (string)$stmt2->fetchColumn();
    }

    /**
     * Find who owns a referral code.
     */
    public function ownerOf(string $code): ?string
    {
        $code = strtoupper(trim($code));
        $stmt = $this->db()->prepare('SELECT user_id FROM referral_codes WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Attribute a referred user to a referral code.
     *
     * Prevents self-referral. Idempotent — re-attributing has no effect.
     *
     * @return bool True if the attribution was recorded.
     * @throws \InvalidArgumentException if code is invalid or self-referral.
     */
    public function attribute(string $referredUserId, string $code): bool
    {
        $referredUserId = $this->validateUserId($referredUserId);
        $code           = strtoupper(trim($code));

        $referrerId = $this->ownerOf($code);
        if ($referrerId === null) {
            throw new \InvalidArgumentException("Referral code '{$code}' is invalid.");
        }
        if ($referrerId === $referredUserId) {
            throw new \InvalidArgumentException('Self-referral is not allowed.');
        }

        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $db->prepare(
                'INSERT OR IGNORE INTO referrals (referred_id, referrer_id)
                 VALUES (:referred, :referrer)'
            );
        } else {
            $stmt = $db->prepare(
                'INSERT IGNORE INTO referrals (referred_id, referrer_id)
                 VALUES (:referred, :referrer)'
            );
        }
        $stmt->execute([':referred' => $referredUserId, ':referrer' => $referrerId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark the referred user as converted.
     *
     * @return bool True if the user was attributed and not yet converted.
     */
    public function convert(string $referredUserId): bool
    {
        $referredUserId = $this->validateUserId($referredUserId);
        $stmt           = $this->db()->prepare(
            'UPDATE referrals SET converted = 1, converted_at = CURRENT_TIMESTAMP
             WHERE referred_id = :uid AND converted = 0'
        );
        $stmt->execute([':uid' => $referredUserId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get referral stats for a referrer.
     *
     * @return array{referrals: int, conversions: int}
     */
    public function stats(string $userId): array
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) AS referrals, SUM(converted) AS conversions
             FROM referrals WHERE referrer_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'referrals'   => (int)($row['referrals'] ?? 0),
            'conversions' => (int)($row['conversions'] ?? 0),
        ];
    }

    /**
     * List all referred users for a referrer.
     *
     * @return list<array{referred_id: string, converted: bool, converted_at: string|null}>
     */
    public function listReferrals(string $userId): array
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT referred_id, converted, converted_at FROM referrals
             WHERE referrer_id = :uid ORDER BY created_at ASC'
        );
        $stmt->execute([':uid' => $userId]);
        return array_map(
            static fn (array $r) => [
                'referred_id'  => (string)$r['referred_id'],
                'converted'    => (bool)$r['converted'],
                'converted_at' => $r['converted_at'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Check whether a user was referred (and by whom).
     *
     * @return array{referrer_id: string, converted: bool}|null
     */
    public function getReferral(string $referredUserId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT referrer_id, converted FROM referrals WHERE referred_id = :uid LIMIT 1'
        );
        $stmt->execute([':uid' => $referredUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['referrer_id' => (string)$row['referrer_id'], 'converted' => (bool)$row['converted']];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }

    private function generateCode(): string
    {
        $chars  = self::CODE_CHARS;
        $len    = strlen($chars);
        $result = '';
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $result .= $chars[random_int(0, $len - 1)];
        }
        return $result;
    }
}
