<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Kudos;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Kudos.
 */
final class KudosTest extends TestCase
{
    private PDO $db;
    private Kudos $k;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE kudos (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                from_user  BIGINT       NOT NULL,
                to_user    BIGINT       NOT NULL,
                message    VARCHAR(255) NOT NULL DEFAULT \'\',
                category   VARCHAR(50)  NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->k = new Kudos($this->db);
    }

    public function testGiveAndCounts(): void
    {
        $this->k->give(1, 2, 'great work', 'teamwork');
        $this->k->give(3, 2);
        $this->assertSame(2, $this->k->receivedCount(2));
        $this->assertSame(1, $this->k->givenCount(1));
        $this->assertSame(0, $this->k->givenCount(2));
    }

    public function testReceivedNewestFirst(): void
    {
        $this->k->give(1, 2, 'first');
        $this->k->give(3, 2, 'second');
        $r = $this->k->received(2);
        $this->assertCount(2, $r);
        $this->assertSame('second', $r[0]['message']);
        $this->assertSame(3, $r[0]['from_user']);
    }

    public function testReceivedLimit(): void
    {
        $this->k->give(1, 2);
        $this->k->give(1, 2);
        $this->k->give(1, 2);
        $this->assertCount(2, $this->k->received(2, 2));
    }

    public function testCountByCategory(): void
    {
        $this->k->give(1, 2, '', 'teamwork');
        $this->k->give(3, 2, '', 'teamwork');
        $this->k->give(4, 2, '', 'innovation');
        $counts = $this->k->countByCategory(2);
        $this->assertSame(2, $counts['teamwork']);
        $this->assertSame(1, $counts['innovation']);
    }

    public function testTopRecipients(): void
    {
        $this->k->give(1, 2);
        $this->k->give(3, 2);
        $this->k->give(4, 5);
        $top = $this->k->topRecipients();
        $this->assertSame(2, $top[0]['to_user']);
        $this->assertSame(2, $top[0]['count']);
        $this->assertSame(5, $top[1]['to_user']);
    }

    public function testRemove(): void
    {
        $id = $this->k->give(1, 2);
        $this->k->remove($id);
        $this->assertSame(0, $this->k->receivedCount(2));
    }

    public function testRemoveMissingIsNoop(): void
    {
        $this->k->remove(999);
        $this->assertSame(0, $this->k->receivedCount(2));
    }

    public function testCategoryTrimmed(): void
    {
        $this->k->give(1, 2, '', '  teamwork  ');
        $this->assertSame(1, $this->k->countByCategory(2)['teamwork']);
    }

    public function testSelfKudosRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->k->give(2, 2, 'self five');
    }
}
