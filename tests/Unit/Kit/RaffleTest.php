<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Raffle;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Raffle.
 *
 * Winner identity from a shuffle isn't hard-coded; tests assert *properties*
 * (winner is an entrant, distinct, count) plus seeded determinism.
 */
final class RaffleTest extends TestCase
{
    private PDO $db;
    private Raffle $r;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE raffle_entries (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                raffle      VARCHAR(150) NOT NULL,
                participant VARCHAR(190) NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->r = new Raffle($this->db);
    }

    public function testEnterAndCounts(): void
    {
        $this->r->enter('summer', 'alice', 3);
        $this->r->enter('summer', 'bob');
        $this->assertSame(4, $this->r->entryCount('summer'));
        $this->assertSame(3, $this->r->ticketsFor('summer', 'alice'));
        $this->assertSame(1, $this->r->ticketsFor('summer', 'bob'));
    }

    public function testHasEnteredAndParticipants(): void
    {
        $this->r->enter('summer', 'alice', 2);
        $this->r->enter('summer', 'carol');
        $this->assertTrue($this->r->hasEntered('summer', 'alice'));
        $this->assertFalse($this->r->hasEntered('summer', 'dave'));
        $this->assertSame(['alice', 'carol'], $this->r->participants('summer'));
    }

    public function testDrawReturnsAnEntrant(): void
    {
        $this->r->enter('summer', 'alice', 3);
        $this->r->enter('summer', 'bob', 2);
        $winners = $this->r->draw('summer', 1);
        $this->assertCount(1, $winners);
        $this->assertContains($winners[0], ['alice', 'bob']);
    }

    public function testDrawDistinctWinners(): void
    {
        $this->r->enter('summer', 'a', 5);
        $this->r->enter('summer', 'b', 5);
        $this->r->enter('summer', 'c', 5);
        $winners = $this->r->draw('summer', 3);
        $this->assertCount(3, $winners);
        $this->assertSame($winners, array_values(array_unique($winners))); // no dupes
    }

    public function testDrawCountClampsToParticipants(): void
    {
        $this->r->enter('summer', 'a', 10);
        $this->r->enter('summer', 'b', 10);
        $winners = $this->r->draw('summer', 5); // only 2 distinct participants
        $this->assertCount(2, $winners);
    }

    public function testDrawEmptyRaffleReturnsEmpty(): void
    {
        $this->assertSame([], $this->r->draw('ghost', 1));
    }

    public function testSeededDrawIsDeterministic(): void
    {
        $this->r->enter('summer', 'a', 3);
        $this->r->enter('summer', 'b', 3);
        $this->r->enter('summer', 'c', 3);
        $first  = $this->r->draw('summer', 2, seed: 12345);
        $second = $this->r->draw('summer', 2, seed: 12345);
        $this->assertSame($first, $second);
    }

    public function testRafflesAreSeparate(): void
    {
        $this->r->enter('a', 'alice');
        $this->r->enter('b', 'bob');
        $this->assertSame(1, $this->r->entryCount('a'));
        $this->assertSame(['bob'], $this->r->participants('b'));
    }

    public function testClear(): void
    {
        $this->r->enter('summer', 'a', 3);
        $removed = $this->r->clear('summer');
        $this->assertSame(3, $removed);
        $this->assertSame(0, $this->r->entryCount('summer'));
    }

    public function testEnterRejectsZeroTickets(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->enter('summer', 'a', 0);
    }

    public function testEnterRejectsEmptyParticipant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->enter('summer', '  ');
    }

    public function testDrawRejectsZeroCount(): void
    {
        $this->r->enter('summer', 'a');
        $this->expectException(\InvalidArgumentException::class);
        $this->r->draw('summer', 0);
    }
}
