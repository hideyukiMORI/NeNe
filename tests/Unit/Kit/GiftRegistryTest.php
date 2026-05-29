<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\GiftRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GiftRegistry.
 */
final class GiftRegistryTest extends TestCase
{
    private PDO $db;
    private GiftRegistry $gr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE gift_registry_items (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                registry    VARCHAR(150) NOT NULL,
                item        VARCHAR(190) NOT NULL,
                desired_qty INTEGER      NOT NULL DEFAULT 1,
                claimed_qty INTEGER      NOT NULL DEFAULT 0,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (registry, item)
            )
        ');
        $this->gr = new GiftRegistry($this->db);
    }

    public function testAddItemAndRemaining(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->assertSame(6, $this->gr->remaining('reg', 'glass'));
        $this->assertSame(0, $this->gr->claimedQty('reg', 'glass'));
    }

    public function testClaimReducesRemaining(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->assertTrue($this->gr->claim('reg', 'glass', 4));
        $this->assertSame(4, $this->gr->claimedQty('reg', 'glass'));
        $this->assertSame(2, $this->gr->remaining('reg', 'glass'));
    }

    public function testClaimUpToDesired(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->gr->claim('reg', 'glass', 4);
        $this->assertTrue($this->gr->claim('reg', 'glass', 2));  // 4+2 = 6 ok
        $this->assertTrue($this->gr->isFulfilled('reg', 'glass'));
    }

    public function testClaimExceedingFails(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->gr->claim('reg', 'glass', 4);
        $this->assertFalse($this->gr->claim('reg', 'glass', 3)); // 4+3 > 6
        $this->assertSame(4, $this->gr->claimedQty('reg', 'glass')); // unchanged
    }

    public function testClaimUnknownItemFails(): void
    {
        $this->assertFalse($this->gr->claim('reg', 'ghost', 1));
    }

    public function testUnclaimReleases(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->gr->claim('reg', 'glass', 4);
        $this->gr->unclaim('reg', 'glass', 2);
        $this->assertSame(2, $this->gr->claimedQty('reg', 'glass'));
        $this->assertTrue($this->gr->claim('reg', 'glass', 4)); // room again
    }

    public function testUnclaimClampsAtZero(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->gr->claim('reg', 'glass', 1);
        $this->gr->unclaim('reg', 'glass', 5); // more than claimed
        $this->assertSame(0, $this->gr->claimedQty('reg', 'glass'));
    }

    public function testIsFulfilled(): void
    {
        $this->gr->addItem('reg', 'toaster', 1);
        $this->assertFalse($this->gr->isFulfilled('reg', 'toaster'));
        $this->gr->claim('reg', 'toaster', 1);
        $this->assertTrue($this->gr->isFulfilled('reg', 'toaster'));
    }

    public function testItemsAccounting(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->gr->addItem('reg', 'toaster', 1);
        $this->gr->claim('reg', 'glass', 4);
        $items = $this->gr->items('reg');
        $this->assertSame('glass', $items[0]['item']);
        $this->assertSame(6, $items[0]['desired']);
        $this->assertSame(4, $items[0]['claimed']);
        $this->assertSame(2, $items[0]['remaining']);
    }

    public function testAddItemPreservesClaimedOnRequantify(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->gr->claim('reg', 'glass', 4);
        $this->gr->addItem('reg', 'glass', 10); // bump desired
        $this->assertSame(4, $this->gr->claimedQty('reg', 'glass')); // claims kept
        $this->assertSame(6, $this->gr->remaining('reg', 'glass'));
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM gift_registry_items')->fetchColumn());
    }

    public function testRemoveItem(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->gr->removeItem('reg', 'glass');
        $this->assertSame(0, $this->gr->remaining('reg', 'glass'));
        $this->assertSame([], $this->gr->items('reg'));
    }

    public function testRegistriesAreSeparate(): void
    {
        $this->gr->addItem('a', 'glass', 6);
        $this->gr->addItem('b', 'glass', 6);
        $this->gr->claim('a', 'glass', 6);
        $this->assertTrue($this->gr->isFulfilled('a', 'glass'));
        $this->assertFalse($this->gr->isFulfilled('b', 'glass'));
    }

    public function testAddItemRejectsZeroDesired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gr->addItem('reg', 'glass', 0);
    }

    public function testClaimRejectsZeroQty(): void
    {
        $this->gr->addItem('reg', 'glass', 6);
        $this->expectException(\InvalidArgumentException::class);
        $this->gr->claim('reg', 'glass', 0);
    }
}
