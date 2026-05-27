<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Token-based user invitation system.
 *
 * Generates high-entropy invite tokens, manages the pending → accepted/cancelled
 * lifecycle, and enforces expiry and owner-only cancellation.
 *
 * ## Lifecycle
 *
 * ```
 * pending ──accept()──► accepted
 * pending ──cancel()──► cancelled
 * ```
 *
 * Once a token leaves `pending`, it is dead. Expired tokens return null from
 * `accept()` (caller should send 410). Already-consumed tokens also return null
 * (caller should send 409). Expiry is checked **before** status.
 *
 * ## Schema
 *
 * ```sql
 * CREATE TABLE invitations (
 *     id          INTEGER      PRIMARY KEY AUTOINCREMENT,
 *     inviter_id  VARCHAR(255) NOT NULL,
 *     email       VARCHAR(255) NOT NULL,
 *     token       VARCHAR(64)  NOT NULL UNIQUE,
 *     status      VARCHAR(16)  NOT NULL DEFAULT 'pending',
 *     expires_at  DATETIME     NOT NULL,
 *     accepted_at DATETIME     DEFAULT NULL,
 *     cancelled_at DATETIME    DEFAULT NULL,
 *     created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_invitations_token ON invitations (token);
 * ```
 *
 * ## Usage
 *
 * ```php
 * $inv = new InvitationToken();
 *
 * // Invite
 * ['token' => $token, 'id' => $id] = $inv->create('user:1', 'alice@example.com', 7 * 86400);
 * // Send $token to the recipient by email.
 *
 * // Accept (recipient clicks the invite link)
 * $result = $inv->accept($token);
 * if ($result === null) {
 *     // Expired (410) or already consumed (409)
 * }
 * // $result['inviter_id'] and $result['email'] are available
 *
 * // Cancel (inviter only)
 * $ok = $inv->cancel($token, inviterId: 'user:1');
 * if (!$ok) {
 *     // Not found, not pending, wrong inviter, or expired
 * }
 * ```
 */
final class InvitationToken
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_CANCELLED = 'cancelled';

    private const TABLE       = 'invitations';
    private const DEFAULT_TTL = 7 * 86400; // 7 days

    public function __construct(
        private readonly ?PDO $db = null,
        private readonly string $table = self::TABLE,
    ) {
    }

    /**
     * Create a new pending invitation.
     *
     * @param  string|int $inviterId  The user creating the invitation.
     * @param  string     $email      Recipient email address.
     * @param  int        $ttlSeconds Token validity in seconds (default: 7 days).
     * @return array{token: string, id: int}
     */
    public function create(string|int $inviterId, string $email, int $ttlSeconds = self::DEFAULT_TTL): array
    {
        $db        = $this->db ?? PdoConnection::getInstance();
        $token     = bin2hex(random_bytes(32)); // 64-char hex, 256-bit entropy
        $now       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $ttlSeconds . ' seconds')
            ->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'INSERT INTO ' . $this->table .
            ' (inviter_id, email, token, status, expires_at, created_at)' .
            ' VALUES (:inv, :email, :tok, :status, :exp, :now)'
        );
        $stmt->bindValue(':inv', (string)$inviterId, PDO::PARAM_STR);
        $stmt->bindValue(':email', $email, PDO::PARAM_STR);
        $stmt->bindValue(':tok', $token, PDO::PARAM_STR);
        $stmt->bindValue(':status', self::STATUS_PENDING, PDO::PARAM_STR);
        $stmt->bindValue(':exp', $expiresAt, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->execute();

        return ['token' => $token, 'id' => (int)$db->lastInsertId()];
    }

    /**
     * Accept an invitation.
     *
     * Expiry is checked BEFORE status:
     * - Expired pending token → null (caller: 410 Gone)
     * - Already consumed (accepted/cancelled) → null (caller: 409 Conflict)
     * - Not found → null (caller: 404)
     *
     * @param  string $token Raw invitation token.
     * @return array{id:int,inviter_id:string,email:string,created_at:string}|null
     */
    public function accept(string $token): ?array
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'SELECT id, inviter_id, email, status, expires_at, created_at FROM ' . $this->table .
            ' WHERE token = :t LIMIT 1'
        );
        $stmt->bindValue(':t', $token, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        // Expiry checked BEFORE status (expired pending → 410, not 409)
        if ((string)$row['expires_at'] <= $now) {
            return null;
        }

        if ((string)$row['status'] !== self::STATUS_PENDING) {
            return null;
        }

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET status = :s, accepted_at = :t WHERE id = :id'
        );
        $stmt->bindValue(':s', self::STATUS_ACCEPTED, PDO::PARAM_STR);
        $stmt->bindValue(':t', $now, PDO::PARAM_STR);
        $stmt->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
        $stmt->execute();

        return [
            'id'         => (int)$row['id'],
            'inviter_id' => (string)$row['inviter_id'],
            'email'      => (string)$row['email'],
            'created_at' => (string)$row['created_at'],
        ];
    }

    /**
     * Cancel a pending invitation. Only the original inviter can cancel.
     *
     * Returns true if cancelled.
     * Returns false if not found, not pending, already expired, or wrong inviter.
     *
     * @param string      $token     Raw invitation token.
     * @param string|int  $inviterId Must match the invitation's inviter_id.
     */
    public function cancel(string $token, string|int $inviterId): bool
    {
        $db  = $this->db ?? PdoConnection::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $stmt = $db->prepare(
            'UPDATE ' . $this->table .
            ' SET status = :s, cancelled_at = :t' .
            ' WHERE token = :tok AND inviter_id = :inv AND status = :ps AND expires_at > :now'
        );
        $stmt->bindValue(':s', self::STATUS_CANCELLED, PDO::PARAM_STR);
        $stmt->bindValue(':t', $now, PDO::PARAM_STR);
        $stmt->bindValue(':tok', $token, PDO::PARAM_STR);
        $stmt->bindValue(':inv', (string)$inviterId, PDO::PARAM_STR);
        $stmt->bindValue(':ps', self::STATUS_PENDING, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Find an invitation by token (for display / status check).
     *
     * @param  string $token Raw invitation token.
     * @return array{id:int,inviter_id:string,email:string,status:string,expires_at:string,created_at:string}|null
     */
    public function find(string $token): ?array
    {
        $db   = $this->db ?? PdoConnection::getInstance();
        $stmt = $db->prepare(
            'SELECT id, inviter_id, email, status, expires_at, created_at FROM ' . $this->table .
            ' WHERE token = :t LIMIT 1'
        );
        $stmt->bindValue(':t', $token, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return [
            'id'         => (int)$row['id'],
            'inviter_id' => (string)$row['inviter_id'],
            'email'      => (string)$row['email'],
            'status'     => (string)$row['status'],
            'expires_at' => (string)$row['expires_at'],
            'created_at' => (string)$row['created_at'],
        ];
    }
}
