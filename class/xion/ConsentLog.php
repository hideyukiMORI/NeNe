<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * ConsentLog — immutable record of user consent grants and withdrawals.
 *
 * Stores each consent action (grant or withdraw) as an append-only entry.
 * The current state is always the most recent action for a (user, purpose) pair.
 *
 * Compliant with GDPR/CCPA audit requirements: every change is time-stamped
 * and the full history is preserved.
 *
 * ## Usage
 *
 * ```php
 * $cl = new ConsentLog($pdo);
 *
 * // User grants consent
 * $cl->grant('user-1', 'marketing_email', '1.2', '203.0.113.1');
 *
 * // User withdraws consent
 * $cl->withdraw('user-1', 'marketing_email');
 *
 * // Check current state
 * $cl->hasConsented('user-1', 'marketing_email'); // false
 *
 * // Full history for audit
 * $cl->history('user-1', 'marketing_email');
 *
 * // All active consents for a user
 * $cl->activeConsents('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE consent_log (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     user_id     VARCHAR(255) NOT NULL,
 *     purpose     VARCHAR(100) NOT NULL,
 *     action      VARCHAR(10)  NOT NULL,
 *     policy_ver  VARCHAR(20)  NOT NULL DEFAULT '',
 *     ip_address  VARCHAR(45)  NOT NULL DEFAULT '',
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ConsentLog
{
    private const GRANT    = 'grant';
    private const WITHDRAW = 'withdraw';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a consent grant.
     *
     * @param  string $policyVersion Version of the policy the user consented to.
     * @param  string $ipAddress     Remote IP address.
     * @return int The new record ID.
     * @throws \InvalidArgumentException if user_id or purpose is empty.
     */
    public function grant(
        string $userId,
        string $purpose,
        string $policyVersion = '',
        string $ipAddress = ''
    ): int {
        return $this->append($userId, $purpose, self::GRANT, $policyVersion, $ipAddress);
    }

    /**
     * Record a consent withdrawal.
     *
     * @return int The new record ID.
     * @throws \InvalidArgumentException if user_id or purpose is empty.
     */
    public function withdraw(
        string $userId,
        string $purpose,
        string $policyVersion = '',
        string $ipAddress = ''
    ): int {
        return $this->append($userId, $purpose, self::WITHDRAW, $policyVersion, $ipAddress);
    }

    /**
     * Check whether the user has currently consented to a purpose.
     *
     * Returns false if there is no record or if the latest action is a withdrawal.
     */
    public function hasConsented(string $userId, string $purpose): bool
    {
        [$userId, $purpose] = $this->normalise($userId, $purpose);
        $stmt = $this->db()->prepare(
            'SELECT action FROM consent_log
             WHERE user_id = :uid AND purpose = :purpose
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':purpose' => $purpose]);
        $action = $stmt->fetchColumn();
        return $action === self::GRANT;
    }

    /**
     * Get the most recent consent entry for a (user, purpose) pair.
     *
     * @return array<string,mixed>|null
     */
    public function current(string $userId, string $purpose): ?array
    {
        [$userId, $purpose] = $this->normalise($userId, $purpose);
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, purpose, action, policy_ver, ip_address, created_at
             FROM consent_log
             WHERE user_id = :uid AND purpose = :purpose
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':uid' => $userId, ':purpose' => $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Get the full consent history for a (user, purpose) pair.
     *
     * @return list<array<string,mixed>>
     */
    public function history(string $userId, string $purpose): array
    {
        [$userId, $purpose] = $this->normalise($userId, $purpose);
        $stmt = $this->db()->prepare(
            'SELECT id, user_id, purpose, action, policy_ver, ip_address, created_at
             FROM consent_log
             WHERE user_id = :uid AND purpose = :purpose
             ORDER BY id ASC'
        );
        $stmt->execute([':uid' => $userId, ':purpose' => $purpose]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all purposes for which a user currently has an active (granted) consent.
     *
     * @return list<string>
     */
    public function activeConsents(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        // Latest action per purpose — SQLite and MySQL both support this pattern
        $stmt = $this->db()->prepare(
            'SELECT purpose, action FROM consent_log
             WHERE user_id = :uid
             AND id = (
                 SELECT MAX(id) FROM consent_log c2
                 WHERE c2.user_id = :uid2 AND c2.purpose = consent_log.purpose
             )
             AND action = :action'
        );
        $stmt->execute([':uid' => $userId, ':uid2' => $userId, ':action' => self::GRANT]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'purpose');
    }

    /**
     * Count how many users have currently consented to a purpose.
     */
    public function consentedCount(string $purpose): int
    {
        $purpose = trim($purpose);

        $stmt = $this->db()->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM consent_log cl
             WHERE purpose = :purpose
             AND action = :action
             AND id = (
                 SELECT MAX(id) FROM consent_log c2
                 WHERE c2.user_id = cl.user_id AND c2.purpose = cl.purpose
             )'
        );
        $stmt->execute([':purpose' => $purpose, ':action' => self::GRANT]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete all consent records for a user (GDPR erasure).
     *
     * @return int Number of rows deleted.
     */
    public function eraseUser(string $userId): int
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare('DELETE FROM consent_log WHERE user_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function append(
        string $userId,
        string $purpose,
        string $action,
        string $policyVersion,
        string $ipAddress
    ): int {
        [$userId, $purpose] = $this->normalise($userId, $purpose);
        $db                 = $this->db();

        $db->prepare(
            'INSERT INTO consent_log (user_id, purpose, action, policy_ver, ip_address)
             VALUES (:uid, :purpose, :action, :policy_ver, :ip)'
        )->execute([
            ':uid'        => $userId,
            ':purpose'    => $purpose,
            ':action'     => $action,
            ':policy_ver' => $policyVersion,
            ':ip'         => $ipAddress,
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $userId, string $purpose): array
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
