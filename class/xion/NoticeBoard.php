<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * NoticeBoard — admin-posted announcements with per-user read-acknowledgment.
 *
 * Admins post notices; users acknowledge them. Useful for system announcements,
 * policy updates, maintenance notices, and one-time alerts.
 *
 * ## Usage
 *
 * ```php
 * $nb = new NoticeBoard($pdo);
 *
 * // Post a notice
 * $id = $nb->post('Maintenance tonight at 23:00', 'admin-1');
 *
 * // List active notices
 * $notices = $nb->active();
 *
 * // User reads a notice
 * $nb->acknowledge($noticeId, 'user-1');
 *
 * // Check if user has read it
 * $nb->hasAcknowledged($noticeId, 'user-1'); // true
 *
 * // Unread notices for a user
 * $unread = $nb->unread('user-1');
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE notices (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     body        TEXT        NOT NULL,
 *     posted_by   VARCHAR(255) NOT NULL,
 *     is_active   TINYINT(1)  NOT NULL DEFAULT 1,
 *     created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     expires_at  DATETIME    DEFAULT NULL
 * );
 *
 * CREATE TABLE notice_reads (
 *     notice_id   INTEGER      NOT NULL,
 *     user_id     VARCHAR(255) NOT NULL,
 *     read_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (notice_id, user_id)
 * );
 * ```
 */
final class NoticeBoard
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Post a new notice.
     *
     * @param  string                    $body      Announcement text.
     * @param  string                    $postedBy  Admin/author identifier.
     * @param  \DateTimeImmutable|null   $expiresAt Optional expiry time.
     * @return int  The new notice ID.
     * @throws \InvalidArgumentException if body or posted_by is empty.
     */
    public function post(
        string $body,
        string $postedBy,
        ?\DateTimeImmutable $expiresAt = null
    ): int {
        $body     = trim($body);
        $postedBy = trim($postedBy);
        if ($body === '') {
            throw new \InvalidArgumentException('body must not be empty.');
        }
        if ($postedBy === '') {
            throw new \InvalidArgumentException('posted_by must not be empty.');
        }

        $expireStr = $expiresAt?->format('Y-m-d H:i:s');
        $this->db()->prepare(
            'INSERT INTO notices (body, posted_by, expires_at) VALUES (:body, :by, :exp)'
        )->execute([':body' => $body, ':by' => $postedBy, ':exp' => $expireStr]);

        return (int)$this->db()->lastInsertId();
    }

    /**
     * Deactivate a notice (soft-remove from active feed).
     *
     * @return bool True if the notice was found and deactivated.
     */
    public function deactivate(int $noticeId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE notices SET is_active = 0 WHERE id = :id AND is_active = 1'
        );
        $stmt->execute([':id' => $noticeId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reactivate a previously deactivated notice.
     *
     * @return bool True if the notice was found and reactivated.
     */
    public function reactivate(int $noticeId): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE notices SET is_active = 1 WHERE id = :id AND is_active = 0'
        );
        $stmt->execute([':id' => $noticeId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * List all currently active, non-expired notices (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function active(): array
    {
        $now  = $this->nowString();
        $stmt = $this->db()->prepare(
            'SELECT id, body, posted_by, created_at, expires_at
             FROM notices
             WHERE is_active = 1
               AND (expires_at IS NULL OR expires_at > :now)
             ORDER BY id DESC'
        );
        $stmt->execute([':now' => $now]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get a single notice by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $noticeId): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, body, posted_by, is_active, created_at, expires_at
             FROM notices WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $noticeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Mark a notice as read by a user.
     *
     * Idempotent — acknowledging twice is a no-op.
     *
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function acknowledge(int $noticeId, string $userId): void
    {
        $userId = $this->validateUserId($userId);
        $db     = $this->db();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql    = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO notice_reads (notice_id, user_id) VALUES (:nid, :uid)'
            : 'INSERT IGNORE INTO notice_reads (notice_id, user_id) VALUES (:nid, :uid)';
        $db->prepare($sql)->execute([':nid' => $noticeId, ':uid' => $userId]);
    }

    /**
     * Check if a user has acknowledged a notice.
     */
    public function hasAcknowledged(int $noticeId, string $userId): bool
    {
        $userId = $this->validateUserId($userId);
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM notice_reads WHERE notice_id = :nid AND user_id = :uid'
        );
        $stmt->execute([':nid' => $noticeId, ':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * List active notices not yet acknowledged by a user.
     *
     * @return list<array<string,mixed>>
     * @throws \InvalidArgumentException if user_id is empty.
     */
    public function unread(string $userId): array
    {
        $userId = $this->validateUserId($userId);
        $now    = $this->nowString();
        $stmt   = $this->db()->prepare(
            'SELECT n.id, n.body, n.posted_by, n.created_at, n.expires_at
             FROM notices n
             WHERE n.is_active = 1
               AND (n.expires_at IS NULL OR n.expires_at > :now)
               AND NOT EXISTS (
                   SELECT 1 FROM notice_reads r
                   WHERE r.notice_id = n.id AND r.user_id = :uid
               )
             ORDER BY n.id DESC'
        );
        $stmt->execute([':now' => $now, ':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count how many users have acknowledged a notice.
     */
    public function acknowledgeCount(int $noticeId): int
    {
        $stmt = $this->db()->prepare(
            'SELECT COUNT(*) FROM notice_reads WHERE notice_id = :nid'
        );
        $stmt->execute([':nid' => $noticeId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Hard-delete a notice and all its read records.
     *
     * @return bool True if a row was deleted.
     */
    public function remove(int $noticeId): bool
    {
        $db = $this->db();
        $db->prepare('DELETE FROM notice_reads WHERE notice_id = :id')->execute([':id' => $noticeId]);
        $stmt = $db->prepare('DELETE FROM notices WHERE id = :id');
        $stmt->execute([':id' => $noticeId]);
        return $stmt->rowCount() > 0;
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    private function nowString(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function validateUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new \InvalidArgumentException('user_id must not be empty.');
        }
        return $userId;
    }
}
