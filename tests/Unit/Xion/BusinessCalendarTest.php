<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\BusinessCalendar;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BusinessCalendar.
 *
 * Reference week (calendar 'jp'), with two registered holidays:
 *   2026-01-01 Thu — 元日 (holiday)
 *   2026-01-02 Fri — business day
 *   2026-01-03 Sat — weekend
 *   2026-01-04 Sun — weekend
 *   2026-01-05 Mon — business day
 *   2026-01-06 Tue — business day
 *   2026-01-07 Wed — business day
 *   2026-01-08 Thu — business day
 *   2026-01-09 Fri — business day
 *   2026-01-10 Sat — weekend
 *   2026-01-11 Sun — weekend
 *   2026-01-12 Mon — 成人の日 (holiday)
 *   2026-01-13 Tue — business day
 */
final class BusinessCalendarTest extends TestCase
{
    private PDO $db;
    private BusinessCalendar $cal;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE calendar_holidays (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                cal_key      VARCHAR(50)  NOT NULL,
                holiday_date CHAR(10)     NOT NULL,
                label        VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (cal_key, holiday_date)
            )
        ');
        $this->cal = new BusinessCalendar($this->db);
        $this->cal->addHoliday('jp', '2026-01-01', '元日');       // Thu
        $this->cal->addHoliday('jp', '2026-01-12', '成人の日');   // Mon
    }

    // ── holidays ────────────────────────────────────────────────────────────────

    public function testIsHolidayMatchesRegistered(): void
    {
        $this->assertTrue($this->cal->isHoliday('jp', '2026-01-01'));
        $this->assertFalse($this->cal->isHoliday('jp', '2026-01-02')); // Fri, not registered
    }

    public function testIsHolidayIgnoresWeekends(): void
    {
        // A Saturday is not a *holiday* even though it is a non-business day.
        $this->assertFalse($this->cal->isHoliday('jp', '2026-01-03')); // Sat
    }

    public function testAddHolidayIsIdempotentAndRelabels(): void
    {
        $this->cal->addHoliday('jp', '2026-01-01', 'New Year');
        $list = $this->cal->holidays('jp');
        $this->assertCount(2, $list);
        $this->assertSame('New Year', $list[0]['label']); // 2026-01-01 first
    }

    public function testHolidaysAreScopedByCalendar(): void
    {
        $this->assertFalse($this->cal->isHoliday('us', '2026-01-01'));
        $this->cal->addHoliday('us', '2026-07-04', 'Independence Day');
        $this->assertTrue($this->cal->isHoliday('us', '2026-07-04'));
        $this->assertFalse($this->cal->isHoliday('jp', '2026-07-04'));
    }

    public function testRemoveHoliday(): void
    {
        $this->cal->removeHoliday('jp', '2026-01-01');
        $this->assertFalse($this->cal->isHoliday('jp', '2026-01-01'));
        // Now a Thursday with no holiday → business day.
        $this->assertTrue($this->cal->isBusinessDay('jp', '2026-01-01'));
    }

    public function testRemoveHolidayMissingIsNoop(): void
    {
        $this->cal->removeHoliday('jp', '2026-03-20'); // never registered
        $this->assertCount(2, $this->cal->holidays('jp'));
    }

    public function testHolidaysRangeIsHalfOpen(): void
    {
        // [2026-01-01, 2026-01-12) includes 元日 but not 成人の日 (exclusive end).
        $list = $this->cal->holidays('jp', '2026-01-01', '2026-01-12');
        $this->assertCount(1, $list);
        $this->assertSame('2026-01-01', $list[0]['date']);
    }

    // ── isBusinessDay ───────────────────────────────────────────────────────────

    public function testWeekdayIsBusinessDay(): void
    {
        $this->assertTrue($this->cal->isBusinessDay('jp', '2026-01-05'));  // Mon
        $this->assertTrue($this->cal->isBusinessDay('jp', '2026-01-02'));  // Fri
    }

    public function testWeekendIsNotBusinessDay(): void
    {
        $this->assertFalse($this->cal->isBusinessDay('jp', '2026-01-03')); // Sat
        $this->assertFalse($this->cal->isBusinessDay('jp', '2026-01-04')); // Sun
    }

    public function testHolidayIsNotBusinessDay(): void
    {
        $this->assertFalse($this->cal->isBusinessDay('jp', '2026-01-01')); // Thu holiday
        $this->assertFalse($this->cal->isBusinessDay('jp', '2026-01-12')); // Mon holiday
    }

    // ── addBusinessDays ─────────────────────────────────────────────────────────

    public function testAddBusinessDaysSkipsWeekendAndHoliday(): void
    {
        // From Mon 2026-01-05, +5 business days lands on Tue 2026-01-13,
        // skipping Sat/Sun (10–11) and the Mon holiday (12).
        $this->assertSame('2026-01-13', $this->cal->addBusinessDays('jp', '2026-01-05', 5));
    }

    public function testAddSingleBusinessDayOverWeekend(): void
    {
        // Fri 2026-01-02 +1 → Mon 2026-01-05 (Sat/Sun skipped).
        $this->assertSame('2026-01-05', $this->cal->addBusinessDays('jp', '2026-01-02', 1));
    }

    public function testAddBusinessDaysNegative(): void
    {
        // Tue 2026-01-13, -2 business days: skip Mon holiday (12) + weekend (10–11),
        // → Fri 2026-01-09 (−1), Thu 2026-01-08 (−2).
        $this->assertSame('2026-01-08', $this->cal->addBusinessDays('jp', '2026-01-13', -2));
    }

    public function testAddZeroBusinessDaysReturnsInputEvenIfHoliday(): void
    {
        $this->assertSame('2026-01-01', $this->cal->addBusinessDays('jp', '2026-01-01', 0));
    }

    public function testNextBusinessDayFromHoliday(): void
    {
        // Thu 2026-01-01 holiday → next business day is Fri 2026-01-02.
        $this->assertSame('2026-01-02', $this->cal->nextBusinessDay('jp', '2026-01-01'));
    }

    public function testNextBusinessDayIsStrictlyAfter(): void
    {
        // From a business day, "next" still advances (strictly after).
        $this->assertSame('2026-01-06', $this->cal->nextBusinessDay('jp', '2026-01-05'));
    }

    public function testPreviousBusinessDayFromMonday(): void
    {
        // Mon 2026-01-05 → previous business day Fri 2026-01-02 (skips weekend).
        $this->assertSame('2026-01-02', $this->cal->previousBusinessDay('jp', '2026-01-05'));
    }

    // ── businessDaysBetween ─────────────────────────────────────────────────────

    public function testBusinessDaysBetweenExcludesEnd(): void
    {
        // [2026-01-05 Mon, 2026-01-13 Tue): Mon–Fri (5,6,7,8,9) = 5,
        // weekend (10,11) and Mon holiday (12) excluded; end exclusive.
        $this->assertSame(5, $this->cal->businessDaysBetween('jp', '2026-01-05', '2026-01-13'));
    }

    public function testBusinessDaysBetweenSpanningHoliday(): void
    {
        // [2026-01-01 Thu, 2026-01-06 Tue): Thu holiday(1), Fri(2) bd,
        // Sat/Sun(3,4), Mon(5) bd → 2.
        $this->assertSame(2, $this->cal->businessDaysBetween('jp', '2026-01-01', '2026-01-06'));
    }

    public function testBusinessDaysBetweenEmptyWhenStartEqualsEnd(): void
    {
        $this->assertSame(0, $this->cal->businessDaysBetween('jp', '2026-01-05', '2026-01-05'));
    }

    public function testBusinessDaysBetweenZeroWhenStartAfterEnd(): void
    {
        $this->assertSame(0, $this->cal->businessDaysBetween('jp', '2026-01-13', '2026-01-05'));
    }

    // ── validation ──────────────────────────────────────────────────────────────

    public function testEmptyCalendarKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cal->isBusinessDay('  ', '2026-01-05');
    }

    public function testMalformedDateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cal->isBusinessDay('jp', '2026-13-40');
    }

    public function testNonExistentCalendarHasNoHolidays(): void
    {
        // Unknown calendar: weekdays are business days, weekends are not.
        $this->assertTrue($this->cal->isBusinessDay('zz', '2026-01-01'));  // Thu, no holiday here
        $this->assertFalse($this->cal->isBusinessDay('zz', '2026-01-03')); // Sat
    }
}
