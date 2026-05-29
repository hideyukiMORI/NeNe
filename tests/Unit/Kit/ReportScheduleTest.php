<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ReportSchedule;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReportSchedule.
 */
final class ReportScheduleTest extends TestCase
{
    private PDO $db;
    private ReportSchedule $rs;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE report_schedules (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          VARCHAR(150) NOT NULL,
                recipients    TEXT         NOT NULL DEFAULT \'[]\',
                format        VARCHAR(20)  NOT NULL DEFAULT \'csv\',
                interval_days INTEGER      NOT NULL,
                next_run      DATETIME     NOT NULL,
                active        INTEGER      NOT NULL DEFAULT 1,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (name)
            )
        ');
        $this->rs = new ReportSchedule($this->db);
    }

    public function testScheduleAndGet(): void
    {
        $this->rs->schedule('weekly', 7, ['a@x.com', 'b@x.com'], 'pdf', '2026-06-01 06:00:00');
        $g = $this->rs->get('weekly');
        $this->assertNotNull($g);
        $this->assertSame(7, $g['interval_days']);
        $this->assertSame('pdf', $g['format']);
        $this->assertSame(['a@x.com', 'b@x.com'], $g['recipients']);
        $this->assertSame('2026-06-01 06:00:00', $g['next_run']);
        $this->assertTrue($g['active']);
    }

    public function testDueRespectsNextRun(): void
    {
        $this->rs->schedule('weekly', 7, [], 'csv', '2026-06-01 06:00:00');
        $this->assertSame([], $this->rs->due('2026-06-01 05:00:00'));  // before
        $this->assertCount(1, $this->rs->due('2026-06-01 06:00:00'));  // exactly at next_run
        $this->assertCount(1, $this->rs->due('2026-06-02 00:00:00'));  // after
    }

    public function testMarkGeneratedAdvancesByInterval(): void
    {
        $this->rs->schedule('weekly', 7, [], 'csv', '2026-06-01 06:00:00');
        $this->rs->markGenerated('weekly');
        $this->assertSame('2026-06-08 06:00:00', $this->rs->get('weekly')['next_run']);
        // No longer due at a time before the new next_run.
        $this->assertSame([], $this->rs->due('2026-06-02 00:00:00'));
    }

    public function testMarkGeneratedIsCadencePreserving(): void
    {
        // Advancing from next_run (not from "now") keeps a fixed cadence.
        $this->rs->schedule('daily', 1, [], 'csv', '2026-06-01 00:00:00');
        $this->rs->markGenerated('daily');
        $this->rs->markGenerated('daily');
        $this->assertSame('2026-06-03 00:00:00', $this->rs->get('daily')['next_run']);
    }

    public function testDueSoonestFirst(): void
    {
        $this->rs->schedule('a', 7, [], 'csv', '2026-06-05 00:00:00');
        $this->rs->schedule('b', 7, [], 'csv', '2026-06-01 00:00:00');
        $due = $this->rs->due('2026-06-10 00:00:00');
        $this->assertSame('b', $due[0]['name']);
        $this->assertSame('a', $due[1]['name']);
    }

    public function testPauseExcludesFromDue(): void
    {
        $this->rs->schedule('weekly', 7, [], 'csv', '2026-06-01 06:00:00');
        $this->rs->pause('weekly');
        $this->assertSame([], $this->rs->due('2026-07-01 00:00:00'));
        $this->assertFalse($this->rs->get('weekly')['active']);
    }

    public function testResume(): void
    {
        $this->rs->schedule('weekly', 7, [], 'csv', '2026-06-01 06:00:00');
        $this->rs->pause('weekly');
        $this->rs->resume('weekly');
        $this->assertCount(1, $this->rs->due('2026-07-01 00:00:00'));
    }

    public function testScheduleIsIdempotentAndReactivates(): void
    {
        $this->rs->schedule('weekly', 7, [], 'csv', '2026-06-01 06:00:00');
        $this->rs->pause('weekly');
        $this->rs->schedule('weekly', 30, ['x@y.com'], 'xlsx', '2026-07-01 06:00:00');
        $g = $this->rs->get('weekly');
        $this->assertSame(30, $g['interval_days']);
        $this->assertTrue($g['active']); // re-scheduling re-activates
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM report_schedules')->fetchColumn());
    }

    public function testRemove(): void
    {
        $this->rs->schedule('weekly', 7, [], 'csv', '2026-06-01 06:00:00');
        $this->rs->remove('weekly');
        $this->assertNull($this->rs->get('weekly'));
    }

    public function testGetUnknownIsNull(): void
    {
        $this->assertNull($this->rs->get('nope'));
    }

    public function testMarkGeneratedUnknownThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rs->markGenerated('nope');
    }

    public function testScheduleRejectsZeroInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rs->schedule('weekly', 0);
    }

    public function testScheduleRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rs->schedule('  ', 7);
    }
}
