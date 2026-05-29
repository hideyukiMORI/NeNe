<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\QuietHours;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QuietHours.
 */
final class QuietHoursTest extends TestCase
{
    private PDO $db;
    private QuietHours $qh;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE quiet_hours (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    BIGINT      NOT NULL,
                start_min  INTEGER     NOT NULL,
                end_min    INTEGER     NOT NULL,
                tz         VARCHAR(64) NOT NULL DEFAULT \'UTC\',
                updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id)
            )
        ');
        $this->qh = new QuietHours($this->db);
    }

    public function testNoWindowIsNeverQuiet(): void
    {
        $this->assertFalse($this->qh->hasWindow(42));
        $this->assertFalse($this->qh->isQuiet(42, '03:00'));
        $this->assertNull($this->qh->window(42));
    }

    public function testSameDayWindowHalfOpen(): void
    {
        $this->qh->set(42, '09:00', '17:00');
        $this->assertFalse($this->qh->isQuiet(42, '08:59')); // before
        $this->assertTrue($this->qh->isQuiet(42, '09:00'));  // start inclusive
        $this->assertTrue($this->qh->isQuiet(42, '12:00'));
        $this->assertFalse($this->qh->isQuiet(42, '17:00')); // end exclusive
        $this->assertFalse($this->qh->isQuiet(42, '18:00'));
    }

    public function testOvernightWindowWraps(): void
    {
        $this->qh->set(42, '22:00', '07:00');
        $this->assertTrue($this->qh->isQuiet(42, '22:00'));  // start inclusive
        $this->assertTrue($this->qh->isQuiet(42, '23:30'));
        $this->assertTrue($this->qh->isQuiet(42, '00:00'));  // past midnight
        $this->assertTrue($this->qh->isQuiet(42, '06:59'));
        $this->assertFalse($this->qh->isQuiet(42, '07:00')); // end exclusive
        $this->assertFalse($this->qh->isQuiet(42, '12:00'));
    }

    public function testZeroLengthWindowNeverQuiet(): void
    {
        $this->qh->set(42, '09:00', '09:00');
        $this->assertFalse($this->qh->isQuiet(42, '09:00'));
        $this->assertFalse($this->qh->isQuiet(42, '00:00'));
    }

    public function testWindowReturnsClockStrings(): void
    {
        $this->qh->set(42, '22:00', '07:00', 'Asia/Tokyo');
        $w = $this->qh->window(42);
        $this->assertSame(['start' => '22:00', 'end' => '07:00', 'tz' => 'Asia/Tokyo'], $w);
    }

    public function testSetIsIdempotentPerUser(): void
    {
        $this->qh->set(42, '22:00', '07:00');
        $this->qh->set(42, '23:00', '06:00');
        $this->assertSame('23:00', $this->qh->window(42)['start']);
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM quiet_hours')->fetchColumn());
    }

    public function testUsersAreSeparate(): void
    {
        $this->qh->set(1, '22:00', '07:00');
        $this->assertTrue($this->qh->isQuiet(1, '23:00'));
        $this->assertFalse($this->qh->hasWindow(2));
    }

    public function testClear(): void
    {
        $this->qh->set(42, '22:00', '07:00');
        $this->qh->clear(42);
        $this->assertFalse($this->qh->hasWindow(42));
        $this->assertFalse($this->qh->isQuiet(42, '23:00'));
    }

    public function testClearMissingIsNoop(): void
    {
        $this->qh->clear(99); // no throw
        $this->assertFalse($this->qh->hasWindow(99));
    }

    public function testDefaultTimezoneIsUtc(): void
    {
        $this->qh->set(42, '09:00', '17:00');
        $this->assertSame('UTC', $this->qh->window(42)['tz']);
    }

    public function testSetRejectsMalformedTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qh->set(42, '25:00', '07:00');
    }

    public function testSetRejectsNonClockString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->qh->set(42, '9am', '5pm');
    }

    public function testIsQuietRejectsMalformedTime(): void
    {
        $this->qh->set(42, '22:00', '07:00');
        $this->expectException(\InvalidArgumentException::class);
        $this->qh->isQuiet(42, '99:99');
    }
}
