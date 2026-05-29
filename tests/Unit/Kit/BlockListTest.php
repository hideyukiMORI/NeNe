<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\BlockList;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BlockList.
 */
final class BlockListTest extends TestCase
{
    private PDO $db;
    private BlockList $bl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE block_list (
                blocker_id  VARCHAR(255) NOT NULL,
                blocked_id  VARCHAR(255) NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (blocker_id, blocked_id)
            )
        ');
        $this->bl = new BlockList($this->db);
    }

    // ── block ─────────────────────────────────────────────────────────────────

    public function testBlockCreateRelation(): void
    {
        $this->bl->block('alice', 'bob');
        $this->assertTrue($this->bl->isBlocked('alice', 'bob'));
    }

    public function testBlockIsIdempotent(): void
    {
        $this->bl->block('alice', 'bob');
        $this->bl->block('alice', 'bob'); // should not throw
        $this->assertTrue($this->bl->isBlocked('alice', 'bob'));
    }

    public function testBlockSelfThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bl->block('alice', 'alice');
    }

    public function testBlockIsDirectional(): void
    {
        $this->bl->block('alice', 'bob');
        $this->assertFalse($this->bl->isBlocked('bob', 'alice'));
    }

    // ── unblock ───────────────────────────────────────────────────────────────

    public function testUnblockRemovesRelation(): void
    {
        $this->bl->block('alice', 'bob');
        $this->assertTrue($this->bl->unblock('alice', 'bob'));
        $this->assertFalse($this->bl->isBlocked('alice', 'bob'));
    }

    public function testUnblockReturnsFalseIfNotBlocked(): void
    {
        $this->assertFalse($this->bl->unblock('alice', 'bob'));
    }

    public function testUnblockOnlyRemovesCorrectDirection(): void
    {
        $this->bl->block('alice', 'bob');
        $this->bl->block('bob', 'alice');

        $this->bl->unblock('alice', 'bob');
        $this->assertFalse($this->bl->isBlocked('alice', 'bob'));
        $this->assertTrue($this->bl->isBlocked('bob', 'alice'));
    }

    // ── isBlockedBy ───────────────────────────────────────────────────────────

    public function testIsBlockedByReturnsTrue(): void
    {
        $this->bl->block('alice', 'bob');
        $this->assertTrue($this->bl->isBlockedBy('bob', 'alice'));
    }

    public function testIsBlockedByReturnsFalse(): void
    {
        $this->assertFalse($this->bl->isBlockedBy('bob', 'alice'));
    }

    // ── isEitherBlocked ───────────────────────────────────────────────────────

    public function testIsEitherBlockedTrueWhenAliceBlocksBob(): void
    {
        $this->bl->block('alice', 'bob');
        $this->assertTrue($this->bl->isEitherBlocked('alice', 'bob'));
        $this->assertTrue($this->bl->isEitherBlocked('bob', 'alice')); // symmetric query
    }

    public function testIsEitherBlockedFalseWhenNoBlock(): void
    {
        $this->assertFalse($this->bl->isEitherBlocked('alice', 'bob'));
    }

    public function testIsEitherBlockedTrueWhenBothBlock(): void
    {
        $this->bl->block('alice', 'bob');
        $this->bl->block('bob', 'alice');
        $this->assertTrue($this->bl->isEitherBlocked('alice', 'bob'));
    }

    // ── blockedBy ─────────────────────────────────────────────────────────────

    public function testBlockedByReturnsBlockedIds(): void
    {
        $this->bl->block('alice', 'bob');
        $this->bl->block('alice', 'carol');
        $list = $this->bl->blockedBy('alice');
        $this->assertContains('bob', $list);
        $this->assertContains('carol', $list);
        $this->assertCount(2, $list);
    }

    public function testBlockedByReturnsEmptyForNoBlocks(): void
    {
        $this->assertSame([], $this->bl->blockedBy('alice'));
    }

    public function testBlockedByIsUserScoped(): void
    {
        $this->bl->block('alice', 'bob');
        $this->assertSame([], $this->bl->blockedBy('bob'));
    }

    // ── blockers ──────────────────────────────────────────────────────────────

    public function testBlockersReturnsWhoBlockedUser(): void
    {
        $this->bl->block('alice', 'dave');
        $this->bl->block('bob', 'dave');
        $list = $this->bl->blockers('dave');
        $this->assertContains('alice', $list);
        $this->assertContains('bob', $list);
        $this->assertCount(2, $list);
    }

    public function testBlockersReturnsEmptyIfNobodyBlocked(): void
    {
        $this->assertSame([], $this->bl->blockers('dave'));
    }
}
