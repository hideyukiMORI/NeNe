<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\LeaveRequest;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LeaveRequest.
 */
final class LeaveRequestTest extends TestCase
{
    private PDO $db;
    private LeaveRequest $lr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE leave_requests (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                employee   VARCHAR(190) NOT NULL,
                type       VARCHAR(50)  NOT NULL,
                start_date CHAR(10)     NOT NULL,
                end_date   CHAR(10)     NOT NULL,
                days       INTEGER      NOT NULL,
                status     VARCHAR(10)  NOT NULL DEFAULT \'pending\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->lr = new LeaveRequest($this->db);
    }

    public function testRequestStartsPending(): void
    {
        $id = $this->lr->request('emp', 'vacation', '2026-07-01', '2026-07-05', 5);
        $this->assertSame('pending', $this->lr->status($id));
    }

    public function testApprove(): void
    {
        $id = $this->lr->request('emp', 'vacation', '2026-07-01', '2026-07-05', 5);
        $this->assertTrue($this->lr->approve($id));
        $this->assertSame('approved', $this->lr->status($id));
        $this->assertSame(5, $this->lr->approvedDays('emp'));
    }

    public function testReject(): void
    {
        $id = $this->lr->request('emp', 'vacation', '2026-07-01', '2026-07-05', 5);
        $this->assertTrue($this->lr->reject($id));
        $this->assertSame('rejected', $this->lr->status($id));
        $this->assertSame(0, $this->lr->approvedDays('emp')); // rejected not counted
    }

    public function testCannotApproveTwice(): void
    {
        $id = $this->lr->request('emp', 'vacation', '2026-07-01', '2026-07-05', 5);
        $this->assertTrue($this->lr->approve($id));
        $this->assertFalse($this->lr->approve($id));  // already approved
        $this->assertFalse($this->lr->reject($id));   // can't reject approved
    }

    public function testApprovedDaysByType(): void
    {
        $a = $this->lr->request('emp', 'vacation', '2026-07-01', '2026-07-03', 3);
        $b = $this->lr->request('emp', 'sick', '2026-08-01', '2026-08-02', 2);
        $this->lr->approve($a);
        $this->lr->approve($b);
        $this->assertSame(5, $this->lr->approvedDays('emp'));
        $this->assertSame(3, $this->lr->approvedDays('emp', 'vacation'));
        $this->assertSame(2, $this->lr->approvedDays('emp', 'sick'));
    }

    public function testRequestsForByStatus(): void
    {
        $a = $this->lr->request('emp', 'vacation', '2026-07-01', '2026-07-03', 3);
        $this->lr->request('emp', 'sick', '2026-08-01', '2026-08-02', 2);
        $this->lr->approve($a);
        $this->assertCount(2, $this->lr->requestsFor('emp'));
        $this->assertCount(1, $this->lr->requestsFor('emp', LeaveRequest::STATUS_APPROVED));
        $this->assertCount(1, $this->lr->requestsFor('emp', LeaveRequest::STATUS_PENDING));
    }

    public function testPendingInbox(): void
    {
        $a = $this->lr->request('e1', 'vacation', '2026-07-01', '2026-07-03', 3);
        $this->lr->request('e2', 'sick', '2026-08-01', '2026-08-02', 2);
        $this->lr->approve($a);
        $pending = $this->lr->pending();
        $this->assertCount(1, $pending);
        $this->assertSame('e2', $pending[0]['employee']);
    }

    public function testStatusUnknownIsNull(): void
    {
        $this->assertNull($this->lr->status(999));
        $this->assertFalse($this->lr->approve(999));
    }

    public function testRequestRejectsEndBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lr->request('emp', 'vacation', '2026-07-05', '2026-07-01', 3);
    }

    public function testRequestRejectsZeroDays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lr->request('emp', 'vacation', '2026-07-01', '2026-07-05', 0);
    }

    public function testRequestRejectsBadDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lr->request('emp', 'vacation', '2026-07-32', '2026-07-05', 3);
    }

    public function testRequestRejectsEmptyEmployee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lr->request('  ', 'vacation', '2026-07-01', '2026-07-05', 3);
    }
}
