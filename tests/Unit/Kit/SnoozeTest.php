<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Snooze;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Snooze.
 */
final class SnoozeTest extends TestCase
{
    private PDO $db;
    private Snooze $s;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE snoozes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                owner      VARCHAR(190) NOT NULL,
                item       VARCHAR(190) NOT NULL,
                wake_at    DATETIME     NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (owner, item)
            )
        ');
        $this->s = new Snooze($this->db);
    }

    public function testSnoozeAndIsSnoozed(): void
    {
        $this->s->snooze('u1', 'ticket-42', '2026-05-30 09:00:00');
        $this->assertTrue($this->s->isSnoozed('u1', 'ticket-42', '2026-05-29 12:00:00'));  // before wake
        $this->assertFalse($this->s->isSnoozed('u1', 'ticket-42', '2026-05-30 09:00:00')); // at wake (not > )
        $this->assertFalse($this->s->isSnoozed('u1', 'ticket-42', '2026-05-30 10:00:00')); // after
    }

    public function testWakeAt(): void
    {
        $this->s->snooze('u1', 'x', '2026-05-30 09:00:00');
        $this->assertSame('2026-05-30 09:00:00', $this->s->wakeAt('u1', 'x'));
        $this->assertNull($this->s->wakeAt('u1', 'missing'));
    }

    public function testReSnoozeReplacesWakeTime(): void
    {
        $this->s->snooze('u1', 'x', '2026-05-30 09:00:00');
        $this->s->snooze('u1', 'x', '2026-06-01 09:00:00');
        $this->assertSame('2026-06-01 09:00:00', $this->s->wakeAt('u1', 'x'));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM snoozes')->fetchColumn());
    }

    public function testSnoozedListsFutureSoonestFirst(): void
    {
        $this->s->snooze('u1', 'later', '2026-06-10 09:00:00');
        $this->s->snooze('u1', 'soon', '2026-06-02 09:00:00');
        $snoozed = $this->s->snoozed('u1', '2026-06-01 00:00:00');
        $this->assertCount(2, $snoozed);
        $this->assertSame('soon', $snoozed[0]['item']);
        $this->assertSame('later', $snoozed[1]['item']);
    }

    public function testDueReturnsWokenItems(): void
    {
        $this->s->snooze('u1', 'woken', '2026-05-29 08:00:00');
        $this->s->snooze('u1', 'still', '2026-06-10 09:00:00');
        // asOf 2026-05-29 12:00 → 'woken' is due, 'still' is not
        $due = $this->s->due('u1', '2026-05-29 12:00:00');
        $this->assertCount(1, $due);
        $this->assertSame('woken', $due[0]['item']);
    }

    public function testSnoozedAndDuePartition(): void
    {
        $this->s->snooze('u1', 'a', '2026-05-29 08:00:00'); // woken
        $this->s->snooze('u1', 'b', '2026-06-10 00:00:00'); // future
        $asOf    = '2026-05-29 12:00:00';
        $snoozed = $this->s->snoozed('u1', $asOf);
        $due     = $this->s->due('u1', $asOf);
        $this->assertSame(2, count($snoozed) + count($due));
        $this->assertCount(1, $snoozed);
        $this->assertCount(1, $due);
    }

    public function testUnsnooze(): void
    {
        $this->s->snooze('u1', 'x', '2026-06-10 09:00:00');
        $this->s->unsnooze('u1', 'x');
        $this->assertFalse($this->s->isSnoozed('u1', 'x', '2026-06-01 00:00:00'));
        $this->assertNull($this->s->wakeAt('u1', 'x'));
    }

    public function testUnsnoozeMissingIsNoop(): void
    {
        $this->s->unsnooze('u1', 'ghost');
        $this->assertNull($this->s->wakeAt('u1', 'ghost'));
    }

    public function testClearWokenRemovesOnlyWoken(): void
    {
        $this->s->snooze('u1', 'woken', '2026-05-29 08:00:00');
        $this->s->snooze('u1', 'future', '2026-06-10 09:00:00');
        $removed = $this->s->clearWoken('u1', '2026-05-29 12:00:00');
        $this->assertSame(1, $removed);
        $this->assertTrue($this->s->isSnoozed('u1', 'future', '2026-05-29 12:00:00'));
    }

    public function testOwnersAreSeparate(): void
    {
        $this->s->snooze('u1', 'x', '2026-06-10 09:00:00');
        $this->assertSame([], $this->s->snoozed('u2', '2026-06-01 00:00:00'));
    }

    public function testSnoozeRejectsEmptyOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->s->snooze('  ', 'x', '2026-06-10 09:00:00');
    }

    public function testSnoozeRejectsBadTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->s->snooze('u1', 'x', 'not-a-time');
    }
}
