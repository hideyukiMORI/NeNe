<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\MaintenanceWindow;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MaintenanceWindow.
 */
final class MaintenanceWindowTest extends TestCase
{
    private PDO $db;
    private MaintenanceWindow $mw;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE maintenance_windows (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                scope      VARCHAR(100) NOT NULL,
                starts_at  DATETIME     NOT NULL,
                ends_at    DATETIME     NOT NULL,
                reason     VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->mw = new MaintenanceWindow($this->db);
    }

    public function testScheduleReturnsId(): void
    {
        $id = $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00', 'DB upgrade');
        $this->assertGreaterThan(0, $id);
    }

    public function testIsActiveWithinWindow(): void
    {
        $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00');
        $this->assertTrue($this->mw->isActive('api', '2026-06-01 03:00:00'));
    }

    public function testIsActiveBoundariesAreHalfOpen(): void
    {
        $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00');
        $this->assertTrue($this->mw->isActive('api', '2026-06-01 02:00:00'));  // start inclusive
        $this->assertFalse($this->mw->isActive('api', '2026-06-01 04:00:00')); // end exclusive
        $this->assertFalse($this->mw->isActive('api', '2026-06-01 01:59:59')); // just before
    }

    public function testIsActiveScoped(): void
    {
        $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00');
        $this->assertFalse($this->mw->isActive('web', '2026-06-01 03:00:00'));
    }

    public function testActiveWindowReturnsDetails(): void
    {
        $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00', 'DB upgrade');
        $w = $this->mw->activeWindow('api', '2026-06-01 03:00:00');
        $this->assertNotNull($w);
        $this->assertSame('DB upgrade', $w['reason']);
        $this->assertSame('2026-06-01 02:00:00', $w['starts_at']);
    }

    public function testActiveWindowNullWhenNone(): void
    {
        $this->assertNull($this->mw->activeWindow('api', '2026-06-01 03:00:00'));
    }

    public function testActiveWindowPrefersSoonestEnding(): void
    {
        $this->mw->schedule('api', '2026-06-01 01:00:00', '2026-06-01 06:00:00', 'long');
        $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00', 'short');
        $w = $this->mw->activeWindow('api', '2026-06-01 03:00:00');
        $this->assertNotNull($w);
        $this->assertSame('short', $w['reason']);
    }

    public function testUpcomingListsFutureSoonestFirst(): void
    {
        $this->mw->schedule('api', '2026-06-10 02:00:00', '2026-06-10 03:00:00', 'later');
        $this->mw->schedule('api', '2026-06-05 02:00:00', '2026-06-05 03:00:00', 'sooner');
        $up = $this->mw->upcoming('api', '2026-06-01 00:00:00');
        $this->assertCount(2, $up);
        $this->assertSame('sooner', $up[0]['reason']);
        $this->assertSame('later', $up[1]['reason']);
    }

    public function testUpcomingExcludesActiveAndPast(): void
    {
        $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00', 'active');
        $up = $this->mw->upcoming('api', '2026-06-01 03:00:00'); // currently inside this window
        $this->assertSame([], $up);
    }

    public function testCancel(): void
    {
        $id = $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00');
        $this->mw->cancel($id);
        $this->assertFalse($this->mw->isActive('api', '2026-06-01 03:00:00'));
    }

    public function testCancelMissingIsNoop(): void
    {
        $this->mw->cancel(999); // no throw
        $this->assertNull($this->mw->activeWindow('api', '2026-06-01 03:00:00'));
    }

    public function testPurgeEndedRemovesOnlyFinishedWindows(): void
    {
        $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 04:00:00', 'past');
        $this->mw->schedule('api', '2026-06-10 02:00:00', '2026-06-10 04:00:00', 'future');
        $removed = $this->mw->purgeEnded('2026-06-05 00:00:00');
        $this->assertSame(1, $removed);
        $this->assertCount(1, $this->mw->upcoming('api', '2026-06-01 00:00:00'));
    }

    public function testScheduleRejectsEndBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mw->schedule('api', '2026-06-01 04:00:00', '2026-06-01 02:00:00');
    }

    public function testScheduleRejectsEqualStartEnd(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mw->schedule('api', '2026-06-01 02:00:00', '2026-06-01 02:00:00');
    }

    public function testScheduleRejectsEmptyScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mw->schedule('  ', '2026-06-01 02:00:00', '2026-06-01 04:00:00');
    }
}
