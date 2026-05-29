<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\PledgeDrive;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PledgeDrive.
 */
final class PledgeDriveTest extends TestCase
{
    private PDO $db;
    private PledgeDrive $pd;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE pledge_drives (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       VARCHAR(190) NOT NULL,
                goal_cents INTEGER      NOT NULL,
                deadline   CHAR(10)     NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE pledge_drive_pledges (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                drive_id     BIGINT       NOT NULL,
                backer       VARCHAR(190) NOT NULL,
                amount_cents INTEGER      NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pd = new PledgeDrive($this->db);
    }

    public function testRaisedAndGoalReached(): void
    {
        $id = $this->pd->createDrive('Roof', 500000);
        $this->assertSame(0, $this->pd->raised($id));
        $this->assertFalse($this->pd->goalReached($id));
        $this->pd->pledge($id, 'alice', 200000);
        $this->pd->pledge($id, 'bob', 350000);
        $this->assertSame(550000, $this->pd->raised($id));
        $this->assertTrue($this->pd->goalReached($id));
    }

    public function testGoalReachedExactlyAtGoal(): void
    {
        $id = $this->pd->createDrive('Roof', 1000);
        $this->pd->pledge($id, 'alice', 1000); // exactly the goal
        $this->assertTrue($this->pd->goalReached($id));
        $this->assertSame(0, $this->pd->remaining($id));
    }

    public function testProgressCapsAtOne(): void
    {
        $id = $this->pd->createDrive('Roof', 1000);
        $this->pd->pledge($id, 'alice', 250);
        $this->assertSame(0.25, $this->pd->progress($id));
        $this->pd->pledge($id, 'bob', 5000); // overshoot
        $this->assertSame(1.0, $this->pd->progress($id)); // capped
    }

    public function testRemaining(): void
    {
        $id = $this->pd->createDrive('Roof', 1000);
        $this->pd->pledge($id, 'alice', 300);
        $this->assertSame(700, $this->pd->remaining($id));
    }

    public function testBackerCountIsDistinct(): void
    {
        $id = $this->pd->createDrive('Roof', 1000);
        $this->pd->pledge($id, 'alice', 100);
        $this->pd->pledge($id, 'alice', 100); // same backer twice
        $this->pd->pledge($id, 'bob', 100);
        $this->assertSame(2, $this->pd->backerCount($id));
        $this->assertSame(300, $this->pd->raised($id));
    }

    public function testTopBackersSumsAndOrders(): void
    {
        $id = $this->pd->createDrive('Roof', 10000);
        $this->pd->pledge($id, 'alice', 100);
        $this->pd->pledge($id, 'alice', 400); // alice total 500
        $this->pd->pledge($id, 'bob', 900);
        $top = $this->pd->topBackers($id, 5);
        $this->assertSame('bob', $top[0]['backer']);
        $this->assertSame(900, $top[0]['total']);
        $this->assertSame('alice', $top[1]['backer']);
        $this->assertSame(500, $top[1]['total']);
    }

    public function testTopBackersRespectsLimit(): void
    {
        $id = $this->pd->createDrive('Roof', 10000);
        $this->pd->pledge($id, 'a', 100);
        $this->pd->pledge($id, 'b', 200);
        $this->pd->pledge($id, 'c', 300);
        $this->assertCount(2, $this->pd->topBackers($id, 2));
    }

    public function testDrivesAreSeparate(): void
    {
        $a = $this->pd->createDrive('A', 1000);
        $b = $this->pd->createDrive('B', 1000);
        $this->pd->pledge($a, 'x', 1000);
        $this->assertTrue($this->pd->goalReached($a));
        $this->assertFalse($this->pd->goalReached($b));
        $this->assertSame(0, $this->pd->raised($b));
    }

    public function testUnknownDriveReadsAreSafe(): void
    {
        $this->assertSame(0, $this->pd->raised(999));
        $this->assertSame(0.0, $this->pd->progress(999));
        $this->assertFalse($this->pd->goalReached(999));
        $this->assertSame(0, $this->pd->remaining(999));
    }

    public function testPledgeUnknownDriveThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pd->pledge(999, 'alice', 100);
    }

    public function testCreateDriveRejectsNonPositiveGoal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pd->createDrive('Roof', 0);
    }

    public function testPledgeRejectsNonPositiveAmount(): void
    {
        $id = $this->pd->createDrive('Roof', 1000);
        $this->expectException(\InvalidArgumentException::class);
        $this->pd->pledge($id, 'alice', 0);
    }

    public function testPledgeRejectsEmptyBacker(): void
    {
        $id = $this->pd->createDrive('Roof', 1000);
        $this->expectException(\InvalidArgumentException::class);
        $this->pd->pledge($id, '  ', 100);
    }
}
