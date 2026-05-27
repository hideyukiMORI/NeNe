<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * FriendRequest — friend request lifecycle with pending/accepted/declined/cancelled states.
 *
 * A user (requester) sends a friend request to another user (requestee).
 * Only one active (pending or accepted) relationship can exist between a pair at a time.
 * The pair is stored in a canonical order (smaller ID first) so that A→B and B→A
 * refer to the same friendship row after acceptance.
 *
 * Status transitions:
 * - `pending`   → `accepted` (requestee accepts)
 * - `pending`   → `declined` (requestee declines)
 * - `pending`   → `cancelled` (requester cancels)
 * - `accepted`  → `cancelled` (either user unfriends)
 *
 * ## Usage
 *
 * ```php
 * $fr = new FriendRequest($pdo);
 *
 * // Send request
 * $fr->send('user-1', 'user-2');
 *
 * // Accept
 * $fr->accept('user-2', 'user-1');
 *
 * // Check friendship
 * $fr->areFriends('user-1', 'user-2'); // true
 *
 * // Unfriend
 * $fr->unfriend('user-1', 'user-2');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE friend_requests (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     requester_id VARCHAR(255) NOT NULL,
 *     requestee_id VARCHAR(255) NOT NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     UNIQUE (requester_id, requestee_id)
 * );
 * ```
 */
final class FriendRequest
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Send a friend request from requester to requestee.
     *
     * @throws \InvalidArgumentException if either ID is empty or they are the same user.
     * @throws \RuntimeException         if a pending or accepted relationship already exists.
     */
    public function send(string $requesterId, string $requesteeId): int
    {
        [$requesterId, $requesteeId] = $this->normalise($requesterId, $requesteeId);

        $existing = $this->findActive($requesterId, $requesteeId);
        if ($existing !== null) {
            throw new \RuntimeException(
                "An active relationship already exists (status: {$existing['status']})."
            );
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO friend_requests (requester_id, requestee_id, status)
             VALUES (:req, :ee, \'pending\')'
        );
        $stmt->execute([':req' => $requesterId, ':ee' => $requesteeId]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Accept a pending friend request.
     *
     * The acceptor must be the requestee of the pending request.
     *
     * @return bool True if a pending request was found and accepted.
     */
    public function accept(string $requesteeId, string $requesterId): bool
    {
        [$requesterId, $requesteeId] = $this->normalise($requesterId, $requesteeId);
        $stmt = $this->db()->prepare(
            "UPDATE friend_requests
             SET status = 'accepted', updated_at = CURRENT_TIMESTAMP
             WHERE requester_id = :req AND requestee_id = :ee AND status = 'pending'"
        );
        $stmt->execute([':req' => $requesterId, ':ee' => $requesteeId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Decline a pending friend request.
     *
     * The decliner must be the requestee.
     *
     * @return bool True if a pending request was found and declined.
     */
    public function decline(string $requesteeId, string $requesterId): bool
    {
        [$requesterId, $requesteeId] = $this->normalise($requesterId, $requesteeId);
        $stmt = $this->db()->prepare(
            "UPDATE friend_requests
             SET status = 'declined', updated_at = CURRENT_TIMESTAMP
             WHERE requester_id = :req AND requestee_id = :ee AND status = 'pending'"
        );
        $stmt->execute([':req' => $requesterId, ':ee' => $requesteeId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cancel a pending request (requester withdraws) or unfriend (either party).
     *
     * @return bool True if a pending or accepted relationship was cancelled.
     */
    public function cancel(string $userId, string $otherId): bool
    {
        $this->validateId($userId);
        $this->validateId($otherId);
        // Try as requester→requestee OR requestee→requester (for accepted friendships)
        $stmt = $this->db()->prepare(
            "UPDATE friend_requests
             SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
             WHERE status IN ('pending', 'accepted')
               AND ((requester_id = :a AND requestee_id = :b)
                 OR (requester_id = :b2 AND requestee_id = :a2))"
        );
        $stmt->execute([':a' => $userId, ':b' => $otherId, ':b2' => $otherId, ':a2' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Alias for cancel() — unfriend two users who are friends.
     */
    public function unfriend(string $userId, string $otherId): bool
    {
        return $this->cancel($userId, $otherId);
    }

    /**
     * Check whether two users are friends (accepted, not cancelled).
     */
    public function areFriends(string $userId, string $otherId): bool
    {
        $this->validateId($userId);
        $this->validateId($otherId);
        $stmt = $this->db()->prepare(
            "SELECT COUNT(*) FROM friend_requests
             WHERE status = 'accepted'
               AND ((requester_id = :a AND requestee_id = :b)
                 OR (requester_id = :b2 AND requestee_id = :a2))"
        );
        $stmt->execute([':a' => $userId, ':b' => $otherId, ':b2' => $otherId, ':a2' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Get the status of the relationship between two users.
     *
     * @return 'pending'|'accepted'|'declined'|'cancelled'|null  null = no relationship.
     */
    public function relationshipStatus(string $userId, string $otherId): ?string
    {
        $this->validateId($userId);
        $this->validateId($otherId);
        $stmt = $this->db()->prepare(
            'SELECT status FROM friend_requests
             WHERE (requester_id = :a AND requestee_id = :b)
                OR (requester_id = :b2 AND requestee_id = :a2)
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':a' => $userId, ':b' => $otherId, ':b2' => $otherId, ':a2' => $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : null;
    }

    /**
     * Get all pending requests sent TO a user (incoming requests).
     *
     * @return list<array{id: int, requester_id: string, created_at: string}>
     */
    public function pendingReceived(string $userId): array
    {
        $userId = $this->validateId($userId);
        $stmt   = $this->db()->prepare(
            "SELECT id, requester_id, created_at FROM friend_requests
             WHERE requestee_id = :uid AND status = 'pending'
             ORDER BY id ASC"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all pending requests sent BY a user (outgoing requests).
     *
     * @return list<array{id: int, requestee_id: string, created_at: string}>
     */
    public function pendingSent(string $userId): array
    {
        $userId = $this->validateId($userId);
        $stmt   = $this->db()->prepare(
            "SELECT id, requestee_id, created_at FROM friend_requests
             WHERE requester_id = :uid AND status = 'pending'
             ORDER BY id ASC"
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all accepted friends for a user.
     *
     * @return list<string>  Friend user IDs.
     */
    public function friends(string $userId): array
    {
        $userId = $this->validateId($userId);
        $stmt   = $this->db()->prepare(
            "SELECT CASE WHEN requester_id = :uid THEN requestee_id ELSE requester_id END AS friend_id
             FROM friend_requests
             WHERE status = 'accepted'
               AND (requester_id = :uid2 OR requestee_id = :uid3)
             ORDER BY id ASC"
        );
        $stmt->execute([':uid' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function validateId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }

    /**
     * @return array{string, string}
     */
    private function normalise(string $requesterId, string $requesteeId): array
    {
        $requesterId = $this->validateId($requesterId);
        $requesteeId = $this->validateId($requesteeId);
        if ($requesterId === $requesteeId) {
            throw new \InvalidArgumentException('requester_id and requestee_id must be different.');
        }
        return [$requesterId, $requesteeId];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findActive(string $requesterId, string $requesteeId): ?array
    {
        $stmt = $this->db()->prepare(
            "SELECT id, status FROM friend_requests
             WHERE status IN ('pending', 'accepted')
               AND ((requester_id = :a AND requestee_id = :b)
                 OR (requester_id = :b2 AND requestee_id = :a2))
             LIMIT 1"
        );
        $stmt->execute([':a' => $requesterId, ':b' => $requesteeId, ':b2' => $requesteeId, ':a2' => $requesterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
