<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\PercentageRollout;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PercentageRollout.
 *
 * Bucketing is a deterministic hash, so most tests assert *properties*
 * (determinism, monotonicity, full/empty rollout, approximate distribution)
 * rather than hard-coded crc32 outputs.
 */
final class PercentageRolloutTest extends TestCase
{
    private PDO $db;
    private PercentageRollout $ro;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE rollout_flags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                flag       VARCHAR(100) NOT NULL,
                percentage INTEGER      NOT NULL DEFAULT 0,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (flag)
            )
        ');
        $this->ro = new PercentageRollout($this->db);
    }

    public function testPercentageDefaultsToZero(): void
    {
        $this->assertSame(0, $this->ro->percentageFor('new_ui'));
    }

    public function testSetAndGetPercentage(): void
    {
        $this->ro->setPercentage('new_ui', 25);
        $this->assertSame(25, $this->ro->percentageFor('new_ui'));
    }

    public function testZeroPercentEnablesNobody(): void
    {
        $this->ro->setPercentage('new_ui', 0);
        for ($i = 0; $i < 50; $i++) {
            $this->assertFalse($this->ro->isEnabled('new_ui', "user-{$i}"));
        }
    }

    public function testHundredPercentEnablesEveryone(): void
    {
        $this->ro->setPercentage('new_ui', 100);
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue($this->ro->isEnabled('new_ui', "user-{$i}"));
        }
    }

    public function testUnconfiguredFlagIsDisabled(): void
    {
        $this->assertFalse($this->ro->isEnabled('ghost', 'user-1'));
    }

    public function testDeterministicForSameKey(): void
    {
        $this->ro->setPercentage('new_ui', 50);
        $first = $this->ro->isEnabled('new_ui', 'user-42');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame($first, $this->ro->isEnabled('new_ui', 'user-42'));
        }
    }

    public function testMonotonicMembershipAsPercentageGrows(): void
    {
        // Every key enabled at 30% must remain enabled at 60% and 90%.
        $keys = array_map(static fn (int $i): string => "user-{$i}", range(0, 199));

        $this->ro->setPercentage('f', 30);
        $at30 = array_filter($keys, fn (string $k): bool => $this->ro->isEnabled('f', $k));

        $this->ro->setPercentage('f', 60);
        foreach ($at30 as $k) {
            $this->assertTrue($this->ro->isEnabled('f', $k), "key {$k} dropped out at 60%");
        }

        $this->ro->setPercentage('f', 90);
        foreach ($at30 as $k) {
            $this->assertTrue($this->ro->isEnabled('f', $k), "key {$k} dropped out at 90%");
        }
    }

    public function testApproximateDistribution(): void
    {
        $this->ro->setPercentage('f', 40);
        $enabled = 0;
        $n       = 2000;
        for ($i = 0; $i < $n; $i++) {
            if ($this->ro->isEnabled('f', "user-{$i}")) {
                $enabled++;
            }
        }
        $ratio = $enabled / $n;
        // 40% target; allow generous slack for hash distribution.
        $this->assertGreaterThan(0.32, $ratio);
        $this->assertLessThan(0.48, $ratio);
    }

    public function testDifferentFlagsBucketIndependently(): void
    {
        // Same key, same percentage, different flag → not necessarily the same
        // membership; but each flag is internally consistent.
        $this->ro->setPercentage('a', 50);
        $this->ro->setPercentage('b', 50);
        $this->assertSame($this->ro->isEnabled('a', 'k'), $this->ro->isEnabled('a', 'k'));
        $this->assertSame($this->ro->isEnabled('b', 'k'), $this->ro->isEnabled('b', 'k'));
    }

    public function testEnableFullyAndDisable(): void
    {
        $this->ro->enableFully('f');
        $this->assertSame(100, $this->ro->percentageFor('f'));
        $this->assertTrue($this->ro->isEnabled('f', 'anyone'));

        $this->ro->disable('f');
        $this->assertSame(0, $this->ro->percentageFor('f'));
        $this->assertFalse($this->ro->isEnabled('f', 'anyone'));
    }

    public function testSetPercentageIsIdempotent(): void
    {
        $this->ro->setPercentage('f', 10);
        $this->ro->setPercentage('f', 80);
        $this->assertSame(80, $this->ro->percentageFor('f'));
        $this->assertCount(1, $this->ro->flags());
    }

    public function testRemove(): void
    {
        $this->ro->setPercentage('f', 100);
        $this->ro->remove('f');
        $this->assertSame(0, $this->ro->percentageFor('f'));
        $this->assertFalse($this->ro->isEnabled('f', 'anyone'));
    }

    public function testFlagsListOrdered(): void
    {
        $this->ro->setPercentage('zeta', 10);
        $this->ro->setPercentage('alpha', 20);
        $list = $this->ro->flags();
        $this->assertSame('alpha', $list[0]['flag']);
        $this->assertSame('zeta', $list[1]['flag']);
    }

    public function testSetPercentageRejectsOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ro->setPercentage('f', 101);
    }

    public function testSetPercentageRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ro->setPercentage('f', -1);
    }

    public function testSetPercentageRejectsEmptyFlag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ro->setPercentage('  ', 10);
    }
}
