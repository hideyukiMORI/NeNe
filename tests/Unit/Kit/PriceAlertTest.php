<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\PriceAlert;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PriceAlert.
 */
final class PriceAlertTest extends TestCase
{
    private PDO $db;
    private PriceAlert $pa;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE price_alerts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      BIGINT       NOT NULL,
                item         VARCHAR(190) NOT NULL,
                target_cents INTEGER      NOT NULL,
                triggered    INTEGER      NOT NULL DEFAULT 0,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                triggered_at DATETIME     NULL,
                UNIQUE (user_id, item)
            )
        ');
        $this->pa = new PriceAlert($this->db);
    }

    public function testWatchAndTarget(): void
    {
        $this->pa->watch(1, 'sku', 5000);
        $this->assertTrue($this->pa->isWatching(1, 'sku'));
        $this->assertSame(5000, $this->pa->targetFor(1, 'sku'));
    }

    public function testCheckFiresAtOrBelowTarget(): void
    {
        $this->pa->watch(1, 'sku', 5000); // alert at/below 5000
        $this->pa->watch(2, 'sku', 4500);
        // current 4800 → user 1 (5000>=4800) fires; user 2 (4500) does not
        $fired = $this->pa->check('sku', 4800);
        $this->assertSame([1], $fired);
        $this->assertFalse($this->pa->isWatching(1, 'sku')); // now triggered
        $this->assertTrue($this->pa->isWatching(2, 'sku'));
    }

    public function testCheckBoundaryInclusive(): void
    {
        $this->pa->watch(1, 'sku', 5000);
        $fired = $this->pa->check('sku', 5000); // exactly at target → fires
        $this->assertSame([1], $fired);
    }

    public function testTriggeredAlertDoesNotRefire(): void
    {
        $this->pa->watch(1, 'sku', 5000);
        $this->pa->check('sku', 4000);          // fires
        $this->assertSame([], $this->pa->check('sku', 3000)); // already triggered
    }

    public function testReWatchReArms(): void
    {
        $this->pa->watch(1, 'sku', 5000);
        $this->pa->check('sku', 4000); // triggered
        $this->pa->watch(1, 'sku', 3000); // re-arm with new target
        $this->assertTrue($this->pa->isWatching(1, 'sku'));
        $this->assertSame(3000, $this->pa->targetFor(1, 'sku'));
        $this->assertSame([1], $this->pa->check('sku', 2500));
    }

    public function testCheckFiresMultipleWatchers(): void
    {
        $this->pa->watch(1, 'sku', 5000);
        $this->pa->watch(2, 'sku', 4900);
        $this->pa->watch(3, 'sku', 3000);
        $fired = $this->pa->check('sku', 4800); // users 1,2 fire (>=4800); user 3 (3000) no
        $this->assertSame([1, 2], $fired);
    }

    public function testPending(): void
    {
        $this->pa->watch(1, 'sku', 5000);
        $this->pa->watch(2, 'sku', 3000);
        $this->pa->check('sku', 4800); // user 1 fires
        $this->assertSame([2], $this->pa->pending('sku'));
    }

    public function testItemsAreSeparate(): void
    {
        $this->pa->watch(1, 'a', 5000);
        $this->pa->watch(1, 'b', 5000);
        $this->pa->check('a', 1000);
        $this->assertFalse($this->pa->isWatching(1, 'a'));
        $this->assertTrue($this->pa->isWatching(1, 'b'));
    }

    public function testUnwatch(): void
    {
        $this->pa->watch(1, 'sku', 5000);
        $this->pa->unwatch(1, 'sku');
        $this->assertFalse($this->pa->isWatching(1, 'sku'));
        $this->assertNull($this->pa->targetFor(1, 'sku'));
    }

    public function testCheckNoMatchesReturnsEmpty(): void
    {
        $this->pa->watch(1, 'sku', 3000);
        $this->assertSame([], $this->pa->check('sku', 4000)); // price above target
    }

    public function testWatchRejectsNonPositiveTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pa->watch(1, 'sku', 0);
    }

    public function testWatchRejectsEmptyItem(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pa->watch(1, '  ', 5000);
    }
}
