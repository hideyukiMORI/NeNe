<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\QueueTicket;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QueueTicket.
 */
final class QueueTicketTest extends TestCase
{
    private PDO $db;
    private QueueTicket $q;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE queue_tickets (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                queue     VARCHAR(100) NOT NULL,
                number    INTEGER      NOT NULL,
                label     VARCHAR(190) NOT NULL DEFAULT \'\',
                status    VARCHAR(10)  NOT NULL DEFAULT \'waiting\',
                issued_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (queue, number)
            )
        ');
        $this->q = new QueueTicket($this->db);
    }

    public function testIssueIncrementsNumbers(): void
    {
        $this->assertSame(1, $this->q->issue('deli', 'Alice'));
        $this->assertSame(2, $this->q->issue('deli', 'Bob'));
        $this->assertSame(3, $this->q->issue('deli'));
    }

    public function testWaitingCount(): void
    {
        $this->q->issue('deli');
        $this->q->issue('deli');
        $this->assertSame(2, $this->q->waiting('deli'));
    }

    public function testCallNextAdvances(): void
    {
        $this->q->issue('deli'); // 1
        $this->q->issue('deli'); // 2
        $this->assertSame(1, $this->q->callNext('deli'));
        $this->assertSame(1, $this->q->nowServing('deli'));
        $this->assertSame(1, $this->q->waiting('deli')); // ticket 2 still waiting
        $this->assertSame(2, $this->q->callNext('deli')); // 1 done, 2 serving
        $this->assertSame(2, $this->q->nowServing('deli'));
        $this->assertSame(0, $this->q->waiting('deli'));
    }

    public function testCallNextEmptyReturnsNull(): void
    {
        $this->assertNull($this->q->callNext('deli'));
        $this->assertNull($this->q->nowServing('deli'));
    }

    public function testPosition(): void
    {
        $a = $this->q->issue('deli');
        $b = $this->q->issue('deli');
        $c = $this->q->issue('deli');
        $this->assertSame(1, $this->q->position('deli', $a));
        $this->assertSame(2, $this->q->position('deli', $b));
        $this->assertSame(3, $this->q->position('deli', $c));
        $this->q->callNext('deli'); // a now serving
        $this->assertNull($this->q->position('deli', $a)); // no longer waiting
        $this->assertSame(1, $this->q->position('deli', $b)); // next up
    }

    public function testPositionUnknownIsNull(): void
    {
        $this->assertNull($this->q->position('deli', 99));
    }

    public function testComplete(): void
    {
        $n = $this->q->issue('deli');
        $this->q->complete('deli', $n);
        $this->assertSame(0, $this->q->waiting('deli'));
        $this->assertNull($this->q->position('deli', $n));
    }

    public function testSkipRemovesFromWaiting(): void
    {
        $a = $this->q->issue('deli');
        $b = $this->q->issue('deli');
        $this->q->skip('deli', $a);
        $this->assertSame(1, $this->q->waiting('deli'));
        $this->assertSame(1, $this->q->position('deli', $b)); // b now first
        $this->assertSame($b, $this->q->callNext('deli'));    // skipped a is not served
    }

    public function testQueuesAreSeparate(): void
    {
        $this->q->issue('a');
        $this->assertSame(1, $this->q->issue('b')); // independent numbering
    }

    public function testResetRestartsNumbering(): void
    {
        $this->q->issue('deli');
        $this->q->issue('deli');
        $this->assertSame(2, $this->q->reset('deli'));
        $this->assertSame(1, $this->q->issue('deli')); // numbering restarts
    }

    public function testIssueRejectsEmptyQueue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->q->issue('  ');
    }
}
