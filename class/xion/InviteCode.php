<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * InviteCode — single-use invite codes with usage quota.
 *
 * Codes are 8-char alphanumeric strings (unambiguous charset). Each code has an
 * optional max-uses limit. Once used (or fully consumed), a code becomes invalid.
 * Useful for referral programs, closed betas, and event access.
 *
 * ## Usage
 *
 * ```php
 * $ic = new InviteCode($pdo);
 *
 * // Generate a code
 * $code = $ic->generate('admin-1', 'beta-wave-2', maxUses: 5);
 *
 * // Redeem the code
 * $ic->redeem($code, 'user-42');
 *
 * // Check validity
 * $ic->isValid($code);
 *
 * // Inspect
 * $ic->find($code);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE invite_codes (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     code        VARCHAR(12)  NOT NULL UNIQUE,
 *     created_by  VARCHAR(255) NOT NULL DEFAULT '',
 *     label       VARCHAR(255) NOT NULL DEFAULT '',
 *     max_uses    INTEGER      DEFAULT NULL,
 *     uses        INTEGER      NOT NULL DEFAULT 0,
 *     expires_at  DATETIME     DEFAULT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE invite_code_uses (
 *     id        INTEGER PRIMARY KEY AUTOINCREMENT,
 *     code      VARCHAR(12)  NOT NULL,
 *     user_id   VARCHAR(255) NOT NULL,
 *     used_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (code, user_id)
 * );
 * ```
 */
final class InviteCode
{
    /** Unambiguous charset: no 0/O/1/I/l */
    private const CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Generate a new invite code.
     *
     * @param  int|null               $maxUses   Maximum redemptions (null = unlimited).
     * @param  \DateTimeImmutable|null $expiresAt Optional expiry time.
     * @return string  The new invite code (e.g. "ABCD-EFGH").
     */
    public function generate(
        string $createdBy = '',
        string $label = '',
        ?int $maxUses = null,
        ?\DateTimeImmutable $expiresAt = null
    ): string {
        $code      = $this->randomCode();
        $expireStr = $expiresAt?->format('Y-m-d H:i:s');

        $this->db()->prepare(
            'INSERT INTO invite_codes (code, created_by, label, max_uses, expires_at)
             VALUES (:code, :by, :label, :max, :exp)'
        )->execute([':code' => $code, ':by' => $createdBy, ':label' => $label, ':max' => $maxUses, ':exp' => $expireStr]);

        return $code;
    }

    /**
     * Redeem an invite code for a user.
     *
     * @return bool True if the code was valid and redeemed. False if invalid, expired, or exhausted.
     * @throws \RuntimeException if the user already redeemed this code.
     */
    public function redeem(string $code, string $userId): bool
    {
        $code   = strtoupper(trim($code));
        $userId = trim($userId);

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT id, max_uses, uses FROM invite_codes
             WHERE code = :code
               AND (expires_at IS NULL OR expires_at > :now)
             LIMIT 1'
        );
        $stmt->execute([':code' => $code, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        $maxUses = $row['max_uses'] !== null ? (int)$row['max_uses'] : null;
        $uses    = (int)$row['uses'];

        if ($maxUses !== null && $uses >= $maxUses) {
            return false;
        }

        // Record use (unique per user)
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO invite_code_uses (code, user_id) VALUES (:code, :uid)'
            : 'INSERT IGNORE INTO invite_code_uses (code, user_id) VALUES (:code, :uid)';

        $insert = $db->prepare($sql);
        $insert->execute([':code' => $code, ':uid' => $userId]);

        if ($insert->rowCount() === 0) {
            throw new \RuntimeException("User '{$userId}' has already redeemed code '{$code}'.");
        }

        // Increment use counter
        $db->prepare(
            'UPDATE invite_codes SET uses = uses + 1 WHERE code = :code'
        )->execute([':code' => $code]);

        return true;
    }

    /**
     * Check whether a code is still valid (not expired, not exhausted).
     */
    public function isValid(string $code): bool
    {
        $code = strtoupper(trim($code));
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'SELECT max_uses, uses FROM invite_codes
             WHERE code = :code AND (expires_at IS NULL OR expires_at > :now) LIMIT 1'
        );
        $stmt->execute([':code' => $code, ':now' => $now]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }
        $maxUses = $row['max_uses'] !== null ? (int)$row['max_uses'] : null;
        return $maxUses === null || (int)$row['uses'] < $maxUses;
    }

    /**
     * Get the invite code record.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $code): ?array
    {
        $code = strtoupper(trim($code));
        $stmt = $this->db()->prepare(
            'SELECT id, code, created_by, label, max_uses, uses, expires_at, created_at
             FROM invite_codes WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List users who have redeemed a code.
     *
     * @return list<string>
     */
    public function redemptions(string $code): array
    {
        $code = strtoupper(trim($code));
        $stmt = $this->db()->prepare(
            'SELECT user_id FROM invite_code_uses WHERE code = :code ORDER BY used_at ASC'
        );
        $stmt->execute([':code' => $code]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'user_id');
    }

    /**
     * Revoke a code (hard delete along with all redemption records).
     *
     * @return bool True if the code existed.
     */
    public function revoke(string $code): bool
    {
        $code = strtoupper(trim($code));
        $db   = $this->db();
        $db->prepare('DELETE FROM invite_code_uses WHERE code = :code')->execute([':code' => $code]);
        $stmt = $db->prepare('DELETE FROM invite_codes WHERE code = :code');
        $stmt->execute([':code' => $code]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function randomCode(): string
    {
        $charset = self::CHARSET;
        $len     = strlen($charset);
        $code    = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $charset[random_int(0, $len - 1)];
        }
        return $code;
    }
}
