<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\WeightedPicker;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WeightedPicker.
 *
 * Items are walked in insertion (id) order, so with A (70) then B (30) the
 * bands are A = [0, 70) and B = [70, 100).
 */
final class WeightedPickerTest extends TestCase
{
    private PDO $db;
    private WeightedPicker $wp;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE weighted_entries (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                pool       VARCHAR(100) NOT NULL,
                item       VARCHAR(190) NOT NULL,
                weight     INTEGER      NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (pool, item)
            )
        ');
        $this->wp = new WeightedPicker($this->db);
    }

    public function testDeterministicRollBands(): void
    {
        $this->wp->setWeight('v', 'A', 70);
        $this->wp->setWeight('v', 'B', 30);
        $this->assertSame('A', $this->wp->pick('v', 0));   // start of A
        $this->assertSame('A', $this->wp->pick('v', 69));  // end of A band
        $this->assertSame('B', $this->wp->pick('v', 70));  // start of B band
        $this->assertSame('B', $this->wp->pick('v', 99));  // end of B band
    }

    public function testTotalWeight(): void
    {
        $this->wp->setWeight('v', 'A', 70);
        $this->wp->setWeight('v', 'B', 30);
        $this->assertSame(100, $this->wp->totalWeight('v'));
    }

    public function testPickNullWhenNoPositiveWeight(): void
    {
        $this->assertNull($this->wp->pick('empty'));
        $this->wp->setWeight('v', 'A', 0);
        $this->assertNull($this->wp->pick('v'));
    }

    public function testZeroWeightItemNeverPicked(): void
    {
        $this->wp->setWeight('v', 'A', 0);
        $this->wp->setWeight('v', 'B', 5);
        for ($roll = 0; $roll < 5; $roll++) {
            $this->assertSame('B', $this->wp->pick('v', $roll));
        }
    }

    public function testSetWeightIsIdempotentUpdate(): void
    {
        $this->wp->setWeight('v', 'A', 10);
        $this->wp->setWeight('v', 'A', 40);
        $this->assertSame(40, $this->wp->totalWeight('v'));
        $this->assertCount(1, $this->wp->weights('v'));
    }

    public function testWeightsListed(): void
    {
        $this->wp->setWeight('v', 'B', 30);
        $this->wp->setWeight('v', 'A', 70);
        $w = $this->wp->weights('v');
        $this->assertSame('A', $w[0]['item']); // item order
        $this->assertSame(70, $w[0]['weight']);
    }

    public function testRollOutOfRangeThrows(): void
    {
        $this->wp->setWeight('v', 'A', 10);
        $this->expectException(\InvalidArgumentException::class);
        $this->wp->pick('v', 10); // total is 10 → valid rolls 0..9
    }

    public function testRandomPickDistribution(): void
    {
        $this->wp->setWeight('v', 'A', 80);
        $this->wp->setWeight('v', 'B', 20);
        $a = 0;
        $n = 2000;
        for ($i = 0; $i < $n; $i++) {
            if ($this->wp->pick('v') === 'A') {
                $a++;
            }
        }
        $ratio = $a / $n;
        $this->assertGreaterThan(0.72, $ratio); // ~80% with slack
        $this->assertLessThan(0.88, $ratio);
    }

    public function testRemoveItem(): void
    {
        $this->wp->setWeight('v', 'A', 70);
        $this->wp->setWeight('v', 'B', 30);
        $this->wp->remove('v', 'A');
        $this->assertSame(30, $this->wp->totalWeight('v'));
        $this->assertSame('B', $this->wp->pick('v', 0));
    }

    public function testClear(): void
    {
        $this->wp->setWeight('v', 'A', 70);
        $this->wp->setWeight('v', 'B', 30);
        $this->wp->clear('v');
        $this->assertSame(0, $this->wp->totalWeight('v'));
        $this->assertNull($this->wp->pick('v'));
    }

    public function testPoolsIndependent(): void
    {
        $this->wp->setWeight('p1', 'A', 10);
        $this->wp->setWeight('p2', 'X', 5);
        $this->assertSame(10, $this->wp->totalWeight('p1'));
        $this->assertSame(5, $this->wp->totalWeight('p2'));
    }

    public function testSetWeightRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wp->setWeight('v', 'A', -1);
    }

    public function testSetWeightRejectsEmptyItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wp->setWeight('v', '  ', 10);
    }
}
