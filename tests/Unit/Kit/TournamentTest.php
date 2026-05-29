<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Tournament;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Tournament.
 */
final class TournamentTest extends TestCase
{
    private PDO $db;
    private Tournament $t;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE tournament_entrants (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                tournament VARCHAR(150) NOT NULL,
                player     VARCHAR(190) NOT NULL,
                eliminated INTEGER      NOT NULL DEFAULT 0,
                UNIQUE (tournament, player)
            )
        ');
        $this->db->exec('
            CREATE TABLE tournament_matches (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                tournament VARCHAR(150) NOT NULL,
                round      INTEGER      NOT NULL,
                player_a   VARCHAR(190) NOT NULL,
                player_b   VARCHAR(190) NOT NULL,
                winner     VARCHAR(190) NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->t = new Tournament($this->db);
        foreach (['alice', 'bob', 'carol', 'dave'] as $p) {
            $this->t->register('cup', $p);
        }
    }

    public function testFullBracketToChampion(): void
    {
        $this->assertNull($this->t->champion('cup')); // no matches yet
        $this->t->recordMatch('cup', 1, 'alice', 'bob', 'alice');
        $this->t->recordMatch('cup', 1, 'carol', 'dave', 'carol');
        $this->assertSame(['alice', 'carol'], $this->t->remaining('cup'));
        $this->assertNull($this->t->champion('cup')); // two remain
        $this->t->recordMatch('cup', 2, 'alice', 'carol', 'alice');
        $this->assertSame('alice', $this->t->champion('cup'));
    }

    public function testLoserEliminated(): void
    {
        $this->t->recordMatch('cup', 1, 'alice', 'bob', 'alice');
        $this->assertTrue($this->t->isEliminated('cup', 'bob'));
        $this->assertFalse($this->t->isEliminated('cup', 'alice'));
    }

    public function testEntrantsRegistrationOrder(): void
    {
        $this->assertSame(['alice', 'bob', 'carol', 'dave'], $this->t->entrants('cup'));
    }

    public function testRegisterIdempotent(): void
    {
        $this->t->register('cup', 'alice');
        $this->assertCount(4, $this->t->entrants('cup'));
    }

    public function testCannotMatchEliminatedPlayer(): void
    {
        $this->t->recordMatch('cup', 1, 'alice', 'bob', 'alice');
        $this->expectException(\InvalidArgumentException::class);
        $this->t->recordMatch('cup', 2, 'bob', 'carol', 'carol'); // bob eliminated
    }

    public function testCannotMatchUnregistered(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->t->recordMatch('cup', 1, 'alice', 'zara', 'alice');
    }

    public function testWinnerMustBeAPlayer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->t->recordMatch('cup', 1, 'alice', 'bob', 'carol');
    }

    public function testSamePlayerMatchRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->t->recordMatch('cup', 1, 'alice', 'alice', 'alice');
    }

    public function testMatchesByRound(): void
    {
        $this->t->recordMatch('cup', 1, 'alice', 'bob', 'alice');
        $this->t->recordMatch('cup', 1, 'carol', 'dave', 'carol');
        $this->assertCount(2, $this->t->matches('cup', 1));
        $this->assertCount(2, $this->t->matches('cup'));
        $this->assertSame('alice', $this->t->matches('cup', 1)[0]['winner']);
    }

    public function testTournamentsAreSeparate(): void
    {
        $this->t->register('other', 'x');
        $this->t->register('other', 'y');
        $this->t->recordMatch('other', 1, 'x', 'y', 'x');
        $this->assertSame('x', $this->t->champion('other'));
        $this->assertNull($this->t->champion('cup')); // untouched
    }

    public function testRecordMatchRejectsZeroRound(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->t->recordMatch('cup', 0, 'alice', 'bob', 'alice');
    }
}
