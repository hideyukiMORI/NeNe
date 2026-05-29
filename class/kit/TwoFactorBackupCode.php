<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * TwoFactorBackupCode — one-time recovery codes for 2FA.
 *
 * When a user enables two-factor authentication they are given a set of
 * backup codes. Each code can be used exactly once to bypass the 2FA
 * challenge (e.g. when the TOTP device is lost). Codes are stored as
 * SHA-256 hashes. A new set of codes invalidates all previous codes.
 *
 * ## Usage
 *
 * ```php
 * $bc = new TwoFactorBackupCode($pdo);
 *
 * // Issue 10 backup codes for a user
 * $rawCodes = $bc->generate('user-1', 10);
 * // Show $rawCodes to the user once; they are not retrievable later.
 *
 * // Verify and consume a code
 * if ($bc->consume('user-1', $inputCode)) {
 *     // login allowed
 * }
 *
 * // Check remaining codes
 * $remaining = $bc->remaining('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE two_factor_backup_codes (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id    VARCHAR(255) NOT NULL,
 *     code_hash  VARCHAR(64)  NOT NULL,
 *     used       TINYINT(1)   NOT NULL DEFAULT 0,
 *     used_at    DATETIME     NULL,
 *     created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class TwoFactorBackupCode
{
    /** Code length in characters (hyphen-separated groups of 5). */
    private const CODE_BYTES = 5;

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Generate a fresh set of backup codes for a user.
     *
     * Invalidates (deletes) any existing codes first.
     *
     * @param  int $count  Number of codes to generate (1–20).
     * @return list<string>  The raw codes. Show once; not retrievable later.
     * @throws \InvalidArgumentException on empty userId or out-of-range count.
     */
    public function generate(string $userId, int $count = 10): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        if ($count < 1 || $count > 20) {
            throw new \InvalidArgumentException('count must be between 1 and 20.');
        }

        // Delete any existing codes
        $this->invalidateAll($userId);

        $stmt = $this->db()->prepare(
            'INSERT INTO two_factor_backup_codes (user_id, code_hash)
             VALUES (:uid, :hash)'
        );

        $rawCodes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw        = $this->generateRaw();
            $rawCodes[] = $raw;
            $stmt->execute([':uid' => $userId, ':hash' => hash('sha256', $raw)]);
        }
        return $rawCodes;
    }

    /**
     * Attempt to consume a backup code.
     *
     * Finds an unused code whose hash matches, marks it as used.
     *
     * @return bool True if a valid unused code was found and consumed.
     */
    public function consume(string $userId, string $rawCode): bool
    {
        $userId = trim($userId);
        $hash   = hash('sha256', $rawCode);

        $stmt = $this->db()->prepare(
            'SELECT id FROM two_factor_backup_codes
             WHERE user_id = :uid AND code_hash = :hash AND used = 0
             LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $upd  = $this->db()->prepare(
            'UPDATE two_factor_backup_codes SET used = 1, used_at = :now WHERE id = :id'
        );
        $upd->execute([':now' => $now, ':id' => $row['id']]);
        return $upd->rowCount() > 0;
    }

    /**
     * Count the remaining (unused) backup codes for a user.
     */
    public function remaining(string $userId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM two_factor_backup_codes
             WHERE user_id = :uid AND used = 0'
        );
        $stmt->execute([':uid' => trim($userId)]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete all backup codes for a user (e.g. when 2FA is disabled or re-enrolled).
     *
     * @return int Number of rows deleted.
     */
    public function invalidateAll(string $userId): int
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare(
            'DELETE FROM two_factor_backup_codes WHERE user_id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    /**
     * List all backup code records for a user (without exposing hashes).
     *
     * @return list<array{id:int, used:int, used_at:string|null, created_at:string}>
     */
    public function list(string $userId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, used, used_at, created_at
             FROM two_factor_backup_codes
             WHERE user_id = :uid
             ORDER BY id ASC'
        );
        $stmt->execute([':uid' => trim($userId)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => [
            'id'         => (int)$r['id'],
            'used'       => (int)$r['used'],
            'used_at'    => $r['used_at'] ?? null,
            'created_at' => (string)$r['created_at'],
        ], $rows);
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    /**
     * Generate a human-readable backup code (e.g. "a1b2c-3d4e5").
     */
    private function generateRaw(): string
    {
        $hex  = bin2hex(random_bytes(self::CODE_BYTES * 2));
        $part = substr($hex, 0, 10);
        return substr($part, 0, 5) . '-' . substr($part, 5, 5);
    }
}
