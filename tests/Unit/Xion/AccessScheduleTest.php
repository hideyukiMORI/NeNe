<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\AccessSchedule;
use PDO;
use PHPUnit\Framework\TestCase;

final class AccessScheduleTest extends TestCase
{
    private PDO $pdo;
    private AccessSchedule $as;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE access_schedules (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                subject_id VARCHAR(255) NOT NULL,
                resource   VARCHAR(255) NOT NULL,
                days       VARCHAR(20)  NOT NULL,
                time_start VARCHAR(8)   NOT NULL,
                time_end   VARCHAR(8)   NOT NULL,
                active     INTEGER      NOT NULL DEFAULT 1,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->as = new AccessSchedule($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '17:00');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresFields(): void
    {
        $id  = $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '17:00');
        $row = $this->as->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['subject_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('office-wifi', $row['resource']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1,2,3,4,5', $row['days']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('09:00', $row['time_start']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('17:00', $row['time_end']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['active']);
    }

    public function testCreateNormalizesAndSortsDays(): void
    {
        $id  = $this->as->create('user-1', 'r', [5, 1, 3, 1], '09:00', '17:00');
        $row = $this->as->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1,3,5', $row['days']);
    }

    public function testCreateThrowsOnEmptySubjectId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->as->create('', 'resource', [1], '09:00', '17:00');
    }

    public function testCreateThrowsOnEmptyResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->as->create('user-1', '', [1], '09:00', '17:00');
    }

    public function testCreateThrowsOnNoDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->as->create('user-1', 'resource', [], '09:00', '17:00');
    }

    public function testCreateThrowsOnBadTimeFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->as->create('user-1', 'resource', [1], '9:00', '17:00');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->as->get(9999));
    }

    // ── isAllowedAt ───────────────────────────────────────────────────────────

    public function testIsAllowedAtReturnsTrueInsideWindow(): void
    {
        // 2026-06-15 is a Monday (day 1)
        $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '17:00');
        $this->assertTrue($this->as->isAllowedAt('user-1', 'office-wifi', '2026-06-15 10:30:00'));
    }

    public function testIsAllowedAtReturnsFalseOutsideTimeWindow(): void
    {
        // 2026-06-15 is a Monday (day 1), but 08:59 is before start
        $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '17:00');
        $this->assertFalse($this->as->isAllowedAt('user-1', 'office-wifi', '2026-06-15 08:59:00'));
    }

    public function testIsAllowedAtReturnsFalseOnEndTime(): void
    {
        // End time is exclusive
        $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '17:00');
        $this->assertFalse($this->as->isAllowedAt('user-1', 'office-wifi', '2026-06-15 17:00:00'));
    }

    public function testIsAllowedAtReturnsFalseOnWrongDay(): void
    {
        // 2026-06-14 is a Sunday (day 0), weekdays-only schedule
        $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '17:00');
        $this->assertFalse($this->as->isAllowedAt('user-1', 'office-wifi', '2026-06-14 10:00:00'));
    }

    public function testIsAllowedAtReturnsTrueOnWeekend(): void
    {
        // 2026-06-14 is a Sunday (day 0)
        $this->as->create('user-1', 'gaming', [0, 6], '00:00', '23:59');
        $this->assertTrue($this->as->isAllowedAt('user-1', 'gaming', '2026-06-14 12:00:00'));
    }

    public function testIsAllowedAtReturnsFalseWhenNoSchedule(): void
    {
        $this->assertFalse($this->as->isAllowedAt('user-1', 'unknown-resource', '2026-06-15 10:00:00'));
    }

    public function testIsAllowedAtReturnsFalseWhenScheduleInactive(): void
    {
        $id = $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '17:00');
        $this->as->deactivate($id);
        $this->assertFalse($this->as->isAllowedAt('user-1', 'office-wifi', '2026-06-15 10:00:00'));
    }

    public function testMultipleSchedulesOneMatches(): void
    {
        $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '12:00');
        $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '13:00', '17:00');
        $this->assertTrue($this->as->isAllowedAt('user-1', 'office-wifi', '2026-06-15 14:00:00'));
        $this->assertFalse($this->as->isAllowedAt('user-1', 'office-wifi', '2026-06-15 12:30:00'));
    }

    // ── activeFor ─────────────────────────────────────────────────────────────

    public function testActiveForFiltersInactiveSchedules(): void
    {
        $id1 = $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '17:00');
        $this->as->create('user-1', 'office-wifi', [6], '10:00', '16:00');
        $this->as->deactivate($id1);
        $this->assertCount(1, $this->as->activeFor('user-1', 'office-wifi'));
    }

    // ── forSubject ────────────────────────────────────────────────────────────

    public function testForSubjectReturnsAllSchedules(): void
    {
        $this->as->create('user-1', 'office-wifi', [1, 2, 3, 4, 5], '09:00', '17:00');
        $this->as->create('user-1', 'admin-panel', [1, 2, 3, 4, 5], '09:00', '17:00');
        $this->as->create('user-2', 'office-wifi', [1], '08:00', '20:00');
        $this->assertCount(2, $this->as->forSubject('user-1'));
    }

    // ── deactivate / activate ─────────────────────────────────────────────────

    public function testDeactivateReturnsTrueWhenActive(): void
    {
        $id = $this->as->create('user-1', 'resource', [1], '09:00', '17:00');
        $this->assertTrue($this->as->deactivate($id));
    }

    public function testDeactivateReturnsFalseWhenAlreadyInactive(): void
    {
        $id = $this->as->create('user-1', 'resource', [1], '09:00', '17:00');
        $this->as->deactivate($id);
        $this->assertFalse($this->as->deactivate($id));
    }

    public function testActivateRestoresInactiveSchedule(): void
    {
        $id = $this->as->create('user-1', 'resource', [1], '09:00', '17:00');
        $this->as->deactivate($id);
        $this->assertTrue($this->as->activate($id));
        $row = $this->as->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['active']);
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesSchedule(): void
    {
        $id = $this->as->create('user-1', 'resource', [1], '09:00', '17:00');
        $this->assertTrue($this->as->delete($id));
        $this->assertNull($this->as->get($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->as->delete(9999));
    }
}
