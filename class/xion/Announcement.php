<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * Announcement — site-wide announcements with scheduling and per-user dismissal.
 *
 * Announcements can be published immediately or scheduled for a future date.
 * An optional expiry automatically hides them. Users can dismiss announcements
 * so they are not shown again for that user.
 *
 * ## Usage
 *
 * ```php
 * $ann = new Announcement($pdo);
 *
 * // Publish immediately
 * $id = $ann->publish('Maintenance tonight 22:00–24:00', 'warning');
 *
 * // Schedule for the future
 * $id = $ann->publish('Black Friday sale!', 'info',
 *     new \DateTimeImmutable('+2 days'),
 *     new \DateTimeImmutable('+5 days')
 * );
 *
 * // Fetch what to show a user
 * $ann->active('user-1');      // all active, not dismissed by user-1
 *
 * // Dismiss
 * $ann->dismiss($id, 'user-1');
 *
 * // Admin
 * $ann->expire($id);
 * $ann->delete($id);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE announcements (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     body         TEXT         NOT NULL DEFAULT '',
 *     category     VARCHAR(50)  NOT NULL DEFAULT 'info',
 *     publish_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     expire_at    DATETIME     DEFAULT NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 *
 * CREATE TABLE announcement_dismissals (
 *     announcement_id INTEGER      NOT NULL,
 *     user_id         VARCHAR(255) NOT NULL,
 *     dismissed_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
 *     PRIMARY KEY (announcement_id, user_id)
 * );
 * ```
 */
final class Announcement
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Publish an announcement.
     *
     * @param string                   $category  e.g. 'info', 'warning', 'error'.
     * @param \DateTimeImmutable|null  $publishAt Defaults to now.
     * @param \DateTimeImmutable|null  $expireAt  Optional expiry.
     * @return int The new announcement ID.
     * @throws \InvalidArgumentException if body is empty or expire_at ≤ publish_at.
     */
    public function publish(
        string $body,
        string $category = 'info',
        ?\DateTimeImmutable $publishAt = null,
        ?\DateTimeImmutable $expireAt = null
    ): int {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('announcement body must not be empty.');
        }
        $publishAt = $publishAt ?? new \DateTimeImmutable();
        if ($expireAt !== null && $expireAt <= $publishAt) {
            throw new \InvalidArgumentException('expire_at must be after publish_at.');
        }
        $db = $this->db();
        $db->prepare(
            'INSERT INTO announcements (body, category, publish_at, expire_at)
             VALUES (:body, :cat, :pub, :exp)'
        )->execute([
            ':body' => $body,
            ':cat'  => trim($category) ?: 'info',
            ':pub'  => $publishAt->format('Y-m-d H:i:s'),
            ':exp'  => $expireAt?->format('Y-m-d H:i:s'),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Find an announcement by ID.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT id, body, category, publish_at, expire_at, created_at
             FROM announcements WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * List all currently active announcements not dismissed by the given user.
     *
     * Active = publish_at ≤ now AND (expire_at IS NULL OR expire_at > now).
     *
     * @return list<array<string,mixed>>
     */
    public function active(string $userId): array
    {
        $userId = trim($userId);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt   = $this->db()->prepare(
            'SELECT a.id, a.body, a.category, a.publish_at, a.expire_at
             FROM announcements a
             WHERE a.publish_at <= :now
               AND (a.expire_at IS NULL OR a.expire_at > :now2)
               AND NOT EXISTS (
                   SELECT 1 FROM announcement_dismissals d
                   WHERE d.announcement_id = a.id AND d.user_id = :uid
               )
             ORDER BY a.publish_at DESC'
        );
        $stmt->execute([':now' => $now, ':now2' => $now, ':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Dismiss an announcement for a user (idempotent).
     */
    public function dismiss(int $announcementId, string $userId): void
    {
        $userId = trim($userId);
        $db     = $this->db();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->prepare(
                'INSERT OR IGNORE INTO announcement_dismissals (announcement_id, user_id)
                 VALUES (:aid, :uid)'
            )->execute([':aid' => $announcementId, ':uid' => $userId]);
        } else {
            $db->prepare(
                'INSERT IGNORE INTO announcement_dismissals (announcement_id, user_id)
                 VALUES (:aid, :uid)'
            )->execute([':aid' => $announcementId, ':uid' => $userId]);
        }
    }

    /**
     * Check whether a user has dismissed an announcement.
     */
    public function isDismissed(int $announcementId, string $userId): bool
    {
        $userId = trim($userId);
        $stmt   = $this->db()->prepare(
            'SELECT COUNT(*) FROM announcement_dismissals
             WHERE announcement_id = :aid AND user_id = :uid'
        );
        $stmt->execute([':aid' => $announcementId, ':uid' => $userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Immediately expire an announcement (set expire_at to now).
     *
     * @return bool True if the announcement existed.
     */
    public function expire(int $id): bool
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'UPDATE announcements SET expire_at = :now WHERE id = :id'
        );
        $stmt->execute([':now' => $now, ':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hard-delete an announcement and all its dismissals.
     *
     * @return bool True if the announcement existed.
     */
    public function delete(int $id): bool
    {
        $db = $this->db();
        $db->prepare('DELETE FROM announcement_dismissals WHERE announcement_id = :id')->execute([':id' => $id]);
        $stmt = $db->prepare('DELETE FROM announcements WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Count all stored announcements.
     */
    public function count(): int
    {
        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM announcements');
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
