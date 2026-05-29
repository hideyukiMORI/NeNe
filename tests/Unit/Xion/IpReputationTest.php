<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\IpReputation;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IpReputation.
 */
final class IpReputationTest extends TestCase
{
    private PDO $db;
    private IpReputation $rep;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE ip_reputation (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                ip         VARCHAR(45) NOT NULL,
                score      INTEGER     NOT NULL DEFAULT 0,
                updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (ip)
            )
        ');
        $this->rep = new IpReputation($this->db);
    }

    public function testPenalizeAccumulates(): void
    {
        $this->assertSame(10, $this->rep->penalize('1.1.1.1', 10));
        $this->assertSame(15, $this->rep->penalize('1.1.1.1', 5));
        $this->assertSame(15, $this->rep->score('1.1.1.1'));
    }

    public function testRewardDecreases(): void
    {
        $this->rep->penalize('1.1.1.1', 15);
        $this->assertSame(12, $this->rep->reward('1.1.1.1', 3));
    }

    public function testAdjustNegative(): void
    {
        $this->rep->penalize('1.1.1.1', 5);
        $this->assertSame(2, $this->rep->adjust('1.1.1.1', -3));
    }

    public function testScoreUnknownIsZero(): void
    {
        $this->assertSame(0, $this->rep->score('9.9.9.9'));
    }

    public function testIsBadThreshold(): void
    {
        $this->rep->penalize('1.1.1.1', 20);
        $this->assertTrue($this->rep->isBad('1.1.1.1', 20));  // >= inclusive
        $this->assertTrue($this->rep->isBad('1.1.1.1', 19));
        $this->assertFalse($this->rep->isBad('1.1.1.1', 21));
    }

    public function testWorstOrderedDesc(): void
    {
        $this->rep->penalize('a', 5);
        $this->rep->penalize('b', 50);
        $this->rep->penalize('c', 20);
        $worst = $this->rep->worst(2);
        $this->assertCount(2, $worst);
        $this->assertSame('b', $worst[0]['ip']);
        $this->assertSame('c', $worst[1]['ip']);
    }

    public function testReset(): void
    {
        $this->rep->penalize('1.1.1.1', 30);
        $this->rep->reset('1.1.1.1');
        $this->assertSame(0, $this->rep->score('1.1.1.1'));
    }

    public function testRemove(): void
    {
        $this->rep->penalize('1.1.1.1', 30);
        $this->rep->remove('1.1.1.1');
        $this->assertSame(0, $this->rep->score('1.1.1.1'));
        $this->assertSame([], $this->rep->worst());
    }

    public function testPurgeBelow(): void
    {
        $this->rep->penalize('a', 100);
        $this->rep->penalize('b', 2);
        $this->rep->penalize('c', 50);
        $removed = $this->rep->purgeBelow(50); // a(100),c(50) kept; b(2) removed
        $this->assertSame(1, $removed);
        $this->assertCount(2, $this->rep->worst());
    }

    public function testAdjustWithinExistingTransaction(): void
    {
        $this->db->beginTransaction();
        $this->rep->penalize('1.1.1.1', 5);
        $this->rep->penalize('1.1.1.1', 5);
        $this->db->commit();
        $this->assertSame(10, $this->rep->score('1.1.1.1'));
    }

    public function testPenalizeRejectsZeroPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rep->penalize('1.1.1.1', 0);
    }

    public function testRewardRejectsZeroPoints(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rep->reward('1.1.1.1', 0);
    }

    public function testAdjustRejectsEmptyIp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rep->adjust('  ', 5);
    }
}
