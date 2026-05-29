<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\EntityAlias;
use PDO;
use PHPUnit\Framework\TestCase;

final class EntityAliasTest extends TestCase
{
    private PDO $pdo;
    private EntityAlias $ea;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE entity_aliases (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                alias       VARCHAR(255) NOT NULL,
                is_primary  TINYINT(1)   NOT NULL DEFAULT 0,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, alias)
            )
        ');
        $this->ea = new EntityAlias($this->pdo);
    }

    // ── register ──────────────────────────────────────────────────────────────

    public function testRegisterReturnsId(): void
    {
        $id = $this->ea->register('user', '42', 'john-doe');
        $this->assertGreaterThan(0, $id);
    }

    public function testRegisterIsIdempotentForSameEntity(): void
    {
        $id1 = $this->ea->register('user', '42', 'john-doe');
        $id2 = $this->ea->register('user', '42', 'john-doe');
        $this->assertSame($id1, $id2);
    }

    public function testRegisterThrowsWhenAliasTakenByOtherEntity(): void
    {
        $this->ea->register('user', '42', 'john-doe');
        $this->expectException(\RuntimeException::class);
        $this->ea->register('user', '99', 'john-doe');
    }

    public function testRegisterThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ea->register('', '42', 'john-doe');
    }

    public function testRegisterThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ea->register('user', '', 'john-doe');
    }

    public function testRegisterThrowsOnEmptyAlias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ea->register('user', '42', '');
    }

    public function testRegisterMarksPrimary(): void
    {
        $this->ea->register('user', '42', 'john-doe', true);
        $list = $this->ea->listAliases('user', '42');
        $this->assertSame(1, (int)$list[0]['is_primary']);
    }

    // ── resolve ───────────────────────────────────────────────────────────────

    public function testResolveReturnsEntityId(): void
    {
        $this->ea->register('user', '42', 'john-doe');
        $this->assertSame('42', $this->ea->resolve('user', 'john-doe'));
    }

    public function testResolveReturnsNullForUnknownAlias(): void
    {
        $this->assertNull($this->ea->resolve('user', 'nobody'));
    }

    public function testResolveIsIsolatedByType(): void
    {
        $this->ea->register('user', '42', 'handle');
        $this->ea->register('org', '99', 'handle');
        $this->assertSame('42', $this->ea->resolve('user', 'handle'));
        $this->assertSame('99', $this->ea->resolve('org', 'handle'));
    }

    // ── listAliases ───────────────────────────────────────────────────────────

    public function testListAliasesReturnsAll(): void
    {
        $this->ea->register('user', '42', 'john-doe');
        $this->ea->register('user', '42', 'johndoe');
        $list = $this->ea->listAliases('user', '42');
        $this->assertCount(2, $list);
    }

    public function testListAliasesReturnsPrimaryFirst(): void
    {
        $this->ea->register('user', '42', 'secondary');
        $this->ea->register('user', '42', 'primary-handle', true);
        $list = $this->ea->listAliases('user', '42');
        $this->assertSame('primary-handle', $list[0]['alias']);
    }

    public function testListAliasesReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ea->listAliases('user', '999'));
    }

    // ── setPrimary ────────────────────────────────────────────────────────────

    public function testSetPrimaryMarksAlias(): void
    {
        $this->ea->register('user', '42', 'handle-a');
        $this->ea->register('user', '42', 'handle-b');
        $this->assertTrue($this->ea->setPrimary('user', '42', 'handle-b'));
        $list = $this->ea->listAliases('user', '42');
        $this->assertSame('handle-b', $list[0]['alias']);
        $this->assertSame(1, (int)$list[0]['is_primary']);
    }

    public function testSetPriclearsPreviousPrimary(): void
    {
        $this->ea->register('user', '42', 'handle-a', true);
        $this->ea->register('user', '42', 'handle-b');
        $this->ea->setPrimary('user', '42', 'handle-b');
        $list         = $this->ea->listAliases('user', '42');
        $primaryAlias = array_filter($list, fn ($r) => (int)$r['is_primary'] === 1);
        $this->assertCount(1, $primaryAlias);
    }

    public function testSetPrimaryReturnsFalseForUnknownAlias(): void
    {
        $this->assertFalse($this->ea->setPrimary('user', '42', 'nonexistent'));
    }

    // ── unregister ────────────────────────────────────────────────────────────

    public function testUnregisterRemovesAlias(): void
    {
        $this->ea->register('user', '42', 'john-doe');
        $this->assertTrue($this->ea->unregister('user', 'john-doe'));
        $this->assertNull($this->ea->resolve('user', 'john-doe'));
    }

    public function testUnregisterReturnsFalseForUnknownAlias(): void
    {
        $this->assertFalse($this->ea->unregister('user', 'nobody'));
    }

    // ── transfer ─────────────────────────────────────────────────────────────

    public function testTransferReassignsAlias(): void
    {
        $this->ea->register('user', '42', 'john-doe');
        $this->assertTrue($this->ea->transfer('user', 'john-doe', '99'));
        $this->assertSame('99', $this->ea->resolve('user', 'john-doe'));
    }

    public function testTransferClearsPrimaryFlag(): void
    {
        $this->ea->register('user', '42', 'john-doe', true);
        $this->ea->transfer('user', 'john-doe', '99');
        $list = $this->ea->listAliases('user', '99');
        $this->assertSame(0, (int)$list[0]['is_primary']);
    }

    public function testTransferReturnsFalseForUnknownAlias(): void
    {
        $this->assertFalse($this->ea->transfer('user', 'nobody', '99'));
    }

    public function testTransferThrowsOnEmptyNewEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ea->transfer('user', 'john-doe', '');
    }
}
