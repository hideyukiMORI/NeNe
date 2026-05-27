<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * FeatureRequest — user-submitted feature requests with voting and status tracking.
 *
 * Lets users submit feature ideas, upvote others' requests, and track
 * implementation status from submitted through to shipped.
 *
 * Distinct from:
 * - `VotePoll`     (named-option polls with yes/no answers)
 * - `VotingBooth`  (election-style ballot voting)
 * - `ContentFlag`  (content moderation reports)
 *
 * ## Usage
 *
 * ```php
 * $fr = new FeatureRequest($pdo);
 *
 * $id = $fr->submit('user-1', 'Dark mode', 'Please add a dark theme');
 * $fr->upvote($id, 'user-2');
 * $fr->upvote($id, 'user-3');
 * $fr->plan($id);     // STATUS_PLANNED
 * $fr->ship($id);     // STATUS_SHIPPED
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE feature_requests (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     submitter_id VARCHAR(255) NOT NULL,
 *     title        TEXT         NOT NULL,
 *     description  TEXT         NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'open',
 *     vote_count   INTEGER      NOT NULL DEFAULT 0,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE TABLE feature_request_votes (
 *     id         INTEGER PRIMARY KEY AUTOINCREMENT,
 *     request_id INTEGER      NOT NULL,
 *     user_id    VARCHAR(255) NOT NULL,
 *     voted_at   DATETIME     NOT NULL,
 *     UNIQUE (request_id, user_id)
 * );
 * ```
 */
final class FeatureRequest
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_PLANNED   = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SHIPPED   = 'shipped';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_DUPLICATE = 'duplicate';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Submit a new feature request.
     *
     * @return int New request ID.
     * @throws \InvalidArgumentException on empty submitterId or title.
     */
    public function submit(string $submitterId, string $title, ?string $description = null): int
    {
        $submitterId = trim($submitterId);
        $title       = trim($title);
        if ($submitterId === '') {
            throw new \InvalidArgumentException('submitter_id must not be empty.');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('title must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO feature_requests (submitter_id, title, description, status, vote_count, created_at)
             VALUES (:sid, :title, :desc, :status, 0, :now)'
        );
        $stmt->execute([
            ':sid'    => $submitterId,
            ':title'  => $title,
            ':desc'   => $description,
            ':status' => self::STATUS_OPEN,
            ':now'    => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Retrieve a feature request by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare('SELECT * FROM feature_requests WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Upvote a feature request. Each user can vote once per request.
     *
     * @return bool True if vote was recorded; false if already voted or request not found.
     * @throws \InvalidArgumentException on empty userId.
     */
    public function upvote(int $requestId, string $userId): bool
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }

        $req = $this->get($requestId);
        if ($req === null) {
            return false;
        }

        // Check if already voted
        $check = $this->db()->prepare(
            'SELECT COUNT(*) FROM feature_request_votes WHERE request_id = :rid AND user_id = :uid'
        );
        $check->execute([':rid' => $requestId, ':uid' => $userId]);
        if ((int)$check->fetchColumn() > 0) {
            return false;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->db()->prepare(
            'INSERT INTO feature_request_votes (request_id, user_id, voted_at)
             VALUES (:rid, :uid, :now)'
        )->execute([':rid' => $requestId, ':uid' => $userId, ':now' => $now]);

        $this->db()->prepare(
            'UPDATE feature_requests SET vote_count = vote_count + 1 WHERE id = :id'
        )->execute([':id' => $requestId]);

        return true;
    }

    /**
     * Remove a user's vote from a feature request.
     *
     * @return bool True if vote existed and was removed.
     */
    public function downvote(int $requestId, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'DELETE FROM feature_request_votes WHERE request_id = :rid AND user_id = :uid'
        );
        $stmt->execute([':rid' => $requestId, ':uid' => trim($userId)]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->db()->prepare(
            'UPDATE feature_requests SET vote_count = MAX(0, vote_count - 1) WHERE id = :id'
        )->execute([':id' => $requestId]);
        return true;
    }

    /**
     * Whether a user has voted on a request.
     */
    public function hasVoted(int $requestId, string $userId): bool
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM feature_request_votes WHERE request_id = :rid AND user_id = :uid'
        );
        $stmt->execute([':rid' => $requestId, ':uid' => trim($userId)]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Move request to PLANNED status.
     *
     * @return bool True if found and was OPEN.
     */
    public function plan(int $requestId): bool
    {
        return $this->transition($requestId, self::STATUS_PLANNED, [self::STATUS_OPEN]);
    }

    /**
     * Move request to IN_PROGRESS status.
     *
     * @return bool True if found and was PLANNED.
     */
    public function startWork(int $requestId): bool
    {
        return $this->transition($requestId, self::STATUS_IN_PROGRESS, [self::STATUS_PLANNED]);
    }

    /**
     * Move request to SHIPPED status.
     *
     * @return bool True if found and was IN_PROGRESS or PLANNED.
     */
    public function ship(int $requestId): bool
    {
        return $this->transition($requestId, self::STATUS_SHIPPED, [self::STATUS_IN_PROGRESS, self::STATUS_PLANNED]);
    }

    /**
     * Decline a feature request.
     *
     * @return bool True if found and was OPEN or PLANNED.
     */
    public function decline(int $requestId): bool
    {
        return $this->transition($requestId, self::STATUS_DECLINED, [self::STATUS_OPEN, self::STATUS_PLANNED]);
    }

    /**
     * Mark request as a duplicate.
     *
     * @return bool True if found and was OPEN.
     */
    public function markDuplicate(int $requestId): bool
    {
        return $this->transition($requestId, self::STATUS_DUPLICATE, [self::STATUS_OPEN]);
    }

    /**
     * Top requests by vote count, optionally filtered by status.
     *
     * @return list<array<string,mixed>>
     */
    public function top(int $limit = 20, ?string $status = null): array
    {
        if ($status !== null) {
            $stmt = $this->db()->prepare(
                'SELECT * FROM feature_requests WHERE status = :status
                 ORDER BY vote_count DESC, created_at ASC
                 LIMIT :limit'
            );
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT * FROM feature_requests
                 ORDER BY vote_count DESC, created_at ASC
                 LIMIT :limit'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * All requests by a specific submitter.
     *
     * @return list<array<string,mixed>>
     */
    public function bySubmitter(string $submitterId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM feature_requests WHERE submitter_id = :sid
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([':sid' => trim($submitterId)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Delete a feature request and all its votes.
     *
     * @return bool True if the request existed.
     */
    public function delete(int $requestId): bool
    {
        $req = $this->get($requestId);
        if ($req === null) {
            return false;
        }
        $this->db()->prepare('DELETE FROM feature_request_votes WHERE request_id = :rid')
            ->execute([':rid' => $requestId]);
        $this->db()->prepare('DELETE FROM feature_requests WHERE id = :id')
            ->execute([':id' => $requestId]);
        return true;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    /**
     * @param list<string> $fromStatuses
     */
    private function transition(int $requestId, string $toStatus, array $fromStatuses): bool
    {
        $placeholders = implode(',', array_fill(0, count($fromStatuses), '?'));
        $stmt = $this->db()->prepare(
            "UPDATE feature_requests SET status = ? WHERE id = ? AND status IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$toStatus, $requestId], $fromStatuses));
        return $stmt->rowCount() > 0;
    }

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
