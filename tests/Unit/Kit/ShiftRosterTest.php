<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ShiftRoster;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ShiftRoster.
 */
final class ShiftRosterTest extends TestCase
{
    private PDO $db;
    private ShiftRoster $r;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE roster_shifts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                shift_date CHAR(10)     NOT NULL,
                name       VARCHAR(100) NOT NULL,
                required   INTEGER      NOT NULL DEFAULT 1,
                UNIQUE (shift_date, name)
            )
        ');
        $this->db->exec('
            CREATE TABLE roster_assignments (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                shift_id BIGINT       NOT NULL,
                worker   VARCHAR(190) NOT NULL,
                UNIQUE (shift_id, worker)
            )
        ');
        $this->r = new ShiftRoster($this->db);
    }

    public function testAssignAndCoverage(): void
    {
        $this->r->defineShift('2026-06-01', 'morning', 2);
        $this->assertFalse($this->r->isCovered('2026-06-01', 'morning'));
        $this->assertTrue($this->r->assign('2026-06-01', 'morning', 'alice'));
        $this->assertTrue($this->r->assign('2026-06-01', 'morning', 'bob'));
        $this->assertTrue($this->r->isCovered('2026-06-01', 'morning')); // 2 >= 2
        $this->assertSame(
            ['required' => 2, 'assigned' => 2, 'short' => 0],
            $this->r->coverage('2026-06-01', 'morning')
        );
    }

    public function testUnderstaffedShortfall(): void
    {
        $this->r->defineShift('2026-06-01', 'night', 3);
        $this->r->assign('2026-06-01', 'night', 'alice');
        $this->assertSame(
            ['required' => 3, 'assigned' => 1, 'short' => 2],
            $this->r->coverage('2026-06-01', 'night')
        );
        $this->assertFalse($this->r->isCovered('2026-06-01', 'night'));
    }

    public function testAssignIsIdempotent(): void
    {
        $this->r->defineShift('2026-06-01', 'morning', 2);
        $this->assertTrue($this->r->assign('2026-06-01', 'morning', 'alice'));
        $this->assertFalse($this->r->assign('2026-06-01', 'morning', 'alice')); // already on
        $this->assertSame(['alice'], $this->r->assignees('2026-06-01', 'morning'));
    }

    public function testUnassign(): void
    {
        $this->r->defineShift('2026-06-01', 'morning', 2);
        $this->r->assign('2026-06-01', 'morning', 'alice');
        $this->assertTrue($this->r->unassign('2026-06-01', 'morning', 'alice'));
        $this->assertFalse($this->r->unassign('2026-06-01', 'morning', 'alice')); // already gone
        $this->assertSame([], $this->r->assignees('2026-06-01', 'morning'));
    }

    public function testDefineShiftUpdatesRequired(): void
    {
        $id1 = $this->r->defineShift('2026-06-01', 'morning', 2);
        $id2 = $this->r->defineShift('2026-06-01', 'morning', 5); // update headcount
        $this->assertSame($id1, $id2); // same shift
        $this->assertSame(5, $this->r->coverage('2026-06-01', 'morning')['required']);
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM roster_shifts')->fetchColumn());
    }

    public function testShiftsForWorker(): void
    {
        $this->r->defineShift('2026-06-01', 'morning', 2);
        $this->r->defineShift('2026-06-01', 'evening', 2);
        $this->r->defineShift('2026-06-02', 'morning', 2);
        $this->r->assign('2026-06-01', 'morning', 'alice');
        $this->r->assign('2026-06-01', 'evening', 'alice');
        $this->r->assign('2026-06-02', 'morning', 'alice'); // different day
        $this->assertSame(['evening', 'morning'], $this->r->shiftsFor('alice', '2026-06-01'));
    }

    public function testAssigneesOrderAndSeparation(): void
    {
        $this->r->defineShift('2026-06-01', 'morning', 3);
        $this->r->assign('2026-06-01', 'morning', 'carol');
        $this->r->assign('2026-06-01', 'morning', 'alice');
        $this->assertSame(['carol', 'alice'], $this->r->assignees('2026-06-01', 'morning')); // insertion order
    }

    public function testUndefinedShiftReads(): void
    {
        $this->assertNull($this->r->coverage('2026-06-01', 'ghost'));
        $this->assertFalse($this->r->isCovered('2026-06-01', 'ghost'));
        $this->assertSame([], $this->r->assignees('2026-06-01', 'ghost'));
    }

    public function testAssignUndefinedShiftThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->assign('2026-06-01', 'ghost', 'alice');
    }

    public function testDefineShiftRejectsZeroRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->defineShift('2026-06-01', 'morning', 0);
    }

    public function testAssignRejectsEmptyWorker(): void
    {
        $this->r->defineShift('2026-06-01', 'morning', 2);
        $this->expectException(\InvalidArgumentException::class);
        $this->r->assign('2026-06-01', 'morning', '  ');
    }
}
