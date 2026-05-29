<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\PageView;
use PDO;
use PHPUnit\Framework\TestCase;

final class PageViewTest extends TestCase
{
    private PDO $pdo;
    private PageView $pv;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE page_views (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                url        VARCHAR(2000) NOT NULL,
                visitor_id VARCHAR(255)  NOT NULL,
                user_id    VARCHAR(255)  NULL,
                referrer   VARCHAR(2000) NULL,
                user_agent TEXT          NULL,
                viewed_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pv = new PageView($this->pdo);
    }

    // ── record ────────────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->pv->record('/blog', 'visitor-1');
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresAllFields(): void
    {
        $this->pv->record('/blog', 'visitor-1', 'user-42', 'https://google.com', 'Mozilla/5.0');
        $this->assertSame(1, $this->pv->count('/blog'));
    }

    public function testRecordThrowsOnEmptyUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pv->record('', 'visitor-1');
    }

    public function testRecordThrowsOnEmptyVisitorId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pv->record('/blog', '');
    }

    // ── count ─────────────────────────────────────────────────────────────────

    public function testCountReturnsTotal(): void
    {
        $this->pv->record('/blog', 'visitor-1');
        $this->pv->record('/blog', 'visitor-2');
        $this->pv->record('/about', 'visitor-1');
        $this->assertSame(2, $this->pv->count('/blog'));
        $this->assertSame(1, $this->pv->count('/about'));
    }

    public function testCountReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->pv->count('/unknown'));
    }

    public function testCountFiltersByPeriod(): void
    {
        $this->pdo->exec("INSERT INTO page_views (url, visitor_id, viewed_at)
                          VALUES ('/blog', 'v1', '2026-04-15 10:00:00')");
        $this->pdo->exec("INSERT INTO page_views (url, visitor_id, viewed_at)
                          VALUES ('/blog', 'v2', '2026-05-15 10:00:00')");
        $this->assertSame(1, $this->pv->count('/blog', '2026-04'));
        $this->assertSame(1, $this->pv->count('/blog', '2026-05'));
    }

    // ── uniqueCount ───────────────────────────────────────────────────────────

    public function testUniqueCountCountsDistinctVisitors(): void
    {
        $this->pv->record('/blog', 'visitor-1');
        $this->pv->record('/blog', 'visitor-1');
        $this->pv->record('/blog', 'visitor-2');
        $this->assertSame(2, $this->pv->uniqueCount('/blog'));
    }

    public function testUniqueCountReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->pv->uniqueCount('/unknown'));
    }

    public function testUniqueCountFiltersByPeriod(): void
    {
        $this->pdo->exec("INSERT INTO page_views (url, visitor_id, viewed_at)
                          VALUES ('/blog', 'v1', '2026-04-15 10:00:00')");
        $this->pdo->exec("INSERT INTO page_views (url, visitor_id, viewed_at)
                          VALUES ('/blog', 'v1', '2026-05-15 10:00:00')");
        $this->assertSame(1, $this->pv->uniqueCount('/blog', '2026-04'));
        $this->assertSame(1, $this->pv->uniqueCount('/blog', '2026-05'));
    }

    // ── topUrls ───────────────────────────────────────────────────────────────

    public function testTopUrlsReturnsOrderedByViews(): void
    {
        $this->pv->record('/home', 'v1');
        $this->pv->record('/blog', 'v1');
        $this->pv->record('/blog', 'v2');
        $this->pv->record('/blog', 'v3');
        $top = $this->pv->topUrls(5);
        $this->assertSame('/blog', $top[0]['url']);
        $this->assertSame(3, (int)$top[0]['views']);
    }

    public function testTopUrlsRespectsLimit(): void
    {
        $this->pv->record('/a', 'v1');
        $this->pv->record('/b', 'v1');
        $this->pv->record('/c', 'v1');
        $top = $this->pv->topUrls(2);
        $this->assertCount(2, $top);
    }

    public function testTopUrlsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->pv->topUrls(5));
    }

    // ── dailyCounts ───────────────────────────────────────────────────────────

    public function testDailyCountsReturnsDayBreakdown(): void
    {
        $today     = (new \DateTimeImmutable())->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d');
        $this->pdo->exec("INSERT INTO page_views (url, visitor_id, viewed_at)
                          VALUES ('/blog', 'v1', '{$today} 10:00:00')");
        $this->pdo->exec("INSERT INTO page_views (url, visitor_id, viewed_at)
                          VALUES ('/blog', 'v2', '{$yesterday} 10:00:00')");
        $daily = $this->pv->dailyCounts('/blog', 7);
        $this->assertArrayHasKey($today, $daily);
        $this->assertArrayHasKey($yesterday, $daily);
        $this->assertSame(1, $daily[$today]);
        $this->assertSame(1, $daily[$yesterday]);
    }

    public function testDailyCountsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->pv->dailyCounts('/unknown', 7));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldRows(): void
    {
        $this->pdo->exec("INSERT INTO page_views (url, visitor_id, viewed_at)
                          VALUES ('/blog', 'v1', '2020-01-01 00:00:00')");
        $this->pv->record('/blog', 'v2');
        $deleted = $this->pv->purgeOlderThan('2025-01-01 00:00:00');
        $this->assertSame(1, $deleted);
        $this->assertSame(1, $this->pv->count('/blog'));
    }
}
