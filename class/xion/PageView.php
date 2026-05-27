<?php

declare(strict_types=1);

namespace Nene\Xion;

use PDO;

/**
 * PageView — page and resource view analytics tracking.
 *
 * Records page views with visitor identification and referrer data. Provides
 * total view counts, unique visitor counts, top-page rankings, and daily
 * breakdowns for basic analytics without a heavy analytics platform.
 *
 * Visitor IDs should be anonymous (e.g. hashed cookie or session ID). IP
 * addresses should be stored in hashed form for GDPR compliance if needed.
 *
 * ## Usage
 *
 * ```php
 * $pv = new PageView($pdo);
 *
 * $pv->record('/blog/hello', 'visitor-abc', null, 'https://google.com');
 * $pv->record('/blog/hello', 'visitor-def', 'user-1');
 *
 * $total   = $pv->count('/blog/hello');           // 2
 * $unique  = $pv->uniqueCount('/blog/hello');     // 2 (distinct visitors)
 * $top     = $pv->topUrls(5);
 * $daily   = $pv->dailyCounts('/blog/hello', 7);
 * ```
 *
 * ## Schema (SQLite / MySQL compatible)
 *
 * ```sql
 * CREATE TABLE page_views (
 *     id          INTEGER PRIMARY KEY AUTOINCREMENT,
 *     url         VARCHAR(2000) NOT NULL,
 *     visitor_id  VARCHAR(255)  NOT NULL,
 *     user_id     VARCHAR(255)  NULL,
 *     referrer    VARCHAR(2000) NULL,
 *     user_agent  TEXT          NULL,
 *     viewed_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
 * );
 * CREATE INDEX idx_page_views_url ON page_views (url, viewed_at);
 * ```
 */
final class PageView
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    // ── public API ────────────────────────────────────────────────────────────

    /**
     * Record a page view.
     *
     * @throws \InvalidArgumentException on empty url or visitor_id.
     */
    public function record(
        string $url,
        string $visitorId,
        ?string $userId = null,
        ?string $referrer = null,
        ?string $userAgent = null
    ): int {
        $url       = trim($url);
        $visitorId = trim($visitorId);
        if ($url === '') {
            throw new \InvalidArgumentException('url must not be empty.');
        }
        if ($visitorId === '') {
            throw new \InvalidArgumentException('visitor_id must not be empty.');
        }

        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->db()->prepare(
            'INSERT INTO page_views (url, visitor_id, user_id, referrer, user_agent, viewed_at)
             VALUES (:url, :vid, :uid, :ref, :ua, :now)'
        );
        $stmt->execute([
            ':url'  => $url,
            ':vid'  => $visitorId,
            ':uid'  => $userId,
            ':ref'  => $referrer,
            ':ua'   => $userAgent,
            ':now'  => $now,
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /**
     * Return total view count for a URL.
     *
     * @param string $period Optional period prefix filter (e.g. '2026-05' matches viewed_at LIKE '2026-05%').
     */
    public function count(string $url, string $period = ''): int
    {
        $url = trim($url);
        if ($period !== '') {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(*) AS cnt FROM page_views WHERE url = :url AND viewed_at LIKE :period'
            );
            $stmt->execute([':url' => $url, ':period' => $period . '%']);
        } else {
            $stmt = $this->db()->prepare('SELECT COUNT(*) AS cnt FROM page_views WHERE url = :url');
            $stmt->execute([':url' => $url]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row !== false ? $row['cnt'] : 0);
    }

    /**
     * Return unique visitor count for a URL.
     *
     * @param string $period Optional period prefix filter.
     */
    public function uniqueCount(string $url, string $period = ''): int
    {
        $url = trim($url);
        if ($period !== '') {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(DISTINCT visitor_id) AS cnt FROM page_views WHERE url = :url AND viewed_at LIKE :period'
            );
            $stmt->execute([':url' => $url, ':period' => $period . '%']);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT COUNT(DISTINCT visitor_id) AS cnt FROM page_views WHERE url = :url'
            );
            $stmt->execute([':url' => $url]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row !== false ? $row['cnt'] : 0);
    }

    /**
     * Return top URLs by view count.
     *
     * @param string $period Optional period prefix filter.
     * @return list<array<string,mixed>> Each row: {url, views}
     */
    public function topUrls(int $limit = 10, string $period = ''): array
    {
        $limit = max(1, $limit);
        if ($period !== '') {
            $stmt = $this->db()->prepare(
                'SELECT url, COUNT(*) AS views
                 FROM page_views
                 WHERE viewed_at LIKE :period
                 GROUP BY url
                 ORDER BY views DESC
                 LIMIT :limit'
            );
            $stmt->bindValue(':period', $period . '%', PDO::PARAM_STR);
        } else {
            $stmt = $this->db()->prepare(
                'SELECT url, COUNT(*) AS views
                 FROM page_views
                 GROUP BY url
                 ORDER BY views DESC
                 LIMIT :limit'
            );
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Return daily view counts for a URL over the last $days days.
     *
     * @return array<string,int> date ('Y-m-d') => count
     */
    public function dailyCounts(string $url, int $days = 7): array
    {
        $url  = trim($url);
        $days = max(1, $days);
        $stmt = $this->db()->prepare(
            'SELECT SUBSTR(viewed_at, 1, 10) AS day, COUNT(*) AS cnt
             FROM page_views
             WHERE url = :url AND viewed_at >= :since
             GROUP BY day
             ORDER BY day ASC'
        );
        $since = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d') . ' 00:00:00';
        $stmt->execute([':url' => $url, ':since' => $since]);
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['day']] = (int)$row['cnt'];
        }
        return $result;
    }

    /**
     * Delete records older than $cutoff.
     *
     * @return int Number of rows deleted.
     */
    public function purgeOlderThan(string $cutoff): int
    {
        $stmt = $this->db()->prepare('DELETE FROM page_views WHERE viewed_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    // ── internal helpers ─────────────────────────────────────────────────────

    private function db(): PDO
    {
        return $this->db ?? PdoConnection::getInstance();
    }
}
