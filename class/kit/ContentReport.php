<?php

declare(strict_types=1);

namespace Nene\Kit;

use Nene\Xion\PdoConnection;
use PDO;

/**
 * User-submitted content moderation reports.
 *
 * Any user can flag a piece of content (post, comment, profile, etc.) with a
 * reason.  A moderator then reviews the report and either actions it (removes
 * or penalises the content) or dismisses it.
 *
 * Unlike ContentFlag (admin-side) or ContentFilter (keyword blocking), this
 * class manages the incoming community-report queue.
 *
 * ## Usage
 *
 * ```php
 * $cr = new ContentReport($pdo);
 *
 * // User reports a post
 * $id = $cr->report('user-1', 'post', '42', 'spam');
 *
 * // Moderator reviews
 * $cr->action($id, 'mod-1');    // content removed/penalised
 * $cr->dismiss($id, 'mod-1');   // report dismissed as invalid
 *
 * // Querying
 * $cr->byStatus(ContentReport::STATUS_PENDING);
 * $cr->forContent('post', '42');
 * $cr->countByReason();
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE content_reports (
 *     id           INTEGER PRIMARY KEY AUTOINCREMENT,
 *     reporter_id  VARCHAR(255) NOT NULL,
 *     content_type VARCHAR(100) NOT NULL,
 *     content_id   VARCHAR(255) NOT NULL,
 *     reason       VARCHAR(100) NOT NULL,
 *     note         TEXT         NULL,
 *     status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
 *     reviewer_id  VARCHAR(255) NULL,
 *     reviewed_at  DATETIME     NULL,
 *     created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * ```
 */
final class ContentReport
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACTIONED = 'actioned';
    public const STATUS_DISMISSED = 'dismissed';

    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }

    // ── report ────────────────────────────────────────────────────────────────

    /**
     * Submit a report from a user.
     *
     * @return int New report ID.
     * @throws \InvalidArgumentException if required fields are empty.
     */
    public function report(
        string $reporterId,
        string $contentType,
        string $contentId,
        string $reason,
        ?string $note = null,
    ): int {
        if ($reporterId === '') {
            throw new \InvalidArgumentException('reporterId must not be empty.');
        }

        if ($contentType === '') {
            throw new \InvalidArgumentException('contentType must not be empty.');
        }

        if ($contentId === '') {
            throw new \InvalidArgumentException('contentId must not be empty.');
        }

        if ($reason === '') {
            throw new \InvalidArgumentException('reason must not be empty.');
        }

        $stmt = $this->db()->prepare(
            'INSERT INTO content_reports
             (reporter_id, content_type, content_id, reason, note, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $reporterId, $contentType, $contentId, $reason, $note,
            self::STATUS_PENDING,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    // ── get ───────────────────────────────────────────────────────────────────

    /**
     * Retrieve a report by ID.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $id): ?array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM content_reports WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── review transitions ────────────────────────────────────────────────────

    /**
     * Mark a pending report as actioned (content removed / penalty applied).
     */
    public function action(int $id, string $reviewerId): bool
    {
        return $this->resolve($id, $reviewerId, self::STATUS_ACTIONED);
    }

    /**
     * Mark a pending report as dismissed (report is invalid or unactionable).
     */
    public function dismiss(int $id, string $reviewerId): bool
    {
        return $this->resolve($id, $reviewerId, self::STATUS_DISMISSED);
    }

    private function resolve(int $id, string $reviewerId, string $status): bool
    {
        $stmt = $this->db()->prepare(
            'UPDATE content_reports
             SET status      = ?,
                 reviewer_id = ?,
                 reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status = ?'
        );
        $stmt->execute([$status, $reviewerId, $id, self::STATUS_PENDING]);
        return $stmt->rowCount() > 0;
    }

    // ── queries ───────────────────────────────────────────────────────────────

    /**
     * Return all reports with a given status, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function byStatus(string $status): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM content_reports WHERE status = ? ORDER BY id DESC'
        );
        $stmt->execute([$status]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all reports against a specific piece of content, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forContent(string $contentType, string $contentId): array
    {
        $stmt = $this->db()->prepare(
            'SELECT * FROM content_reports
             WHERE content_type = ? AND content_id = ?
             ORDER BY id DESC'
        );
        $stmt->execute([$contentType, $contentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a reason → count breakdown across all reports.
     *
     * @return array<string,int>
     */
    public function countByReason(): array
    {
        $stmt = $this->db()->prepare(
            'SELECT reason, COUNT(*) AS cnt
             FROM content_reports
             GROUP BY reason
             ORDER BY cnt DESC'
        );
        $stmt->execute();
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];

        foreach ($rows as $row) {
            $result[(string)$row['reason']] = (int)$row['cnt'];
        }

        return $result;
    }
}
