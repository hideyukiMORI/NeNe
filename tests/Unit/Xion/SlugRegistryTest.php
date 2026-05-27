<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\SlugRegistry;
use PDO;
use PHPUnit\Framework\TestCase;

final class SlugRegistryTest extends TestCase
{
    private PDO $db;
    private SlugRegistry $sr;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE slug_registry (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                namespace  VARCHAR(100) NOT NULL,
                slug       VARCHAR(255) NOT NULL,
                entity_id  VARCHAR(255) NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (namespace, slug),
                UNIQUE (namespace, entity_id)
            )
        ');
        $this->sr = new SlugRegistry($this->db);
    }

    public function testRegisterReturnsSlug(): void
    {
        $slug = $this->sr->register('post', '1', 'Hello World');
        $this->assertSame('hello-world', $slug);
    }

    public function testRegisterAppendsNumberOnConflict(): void
    {
        $this->sr->register('post', '1', 'Hello World');
        $slug2 = $this->sr->register('post', '2', 'Hello World');
        $this->assertSame('hello-world-2', $slug2);
        $slug3 = $this->sr->register('post', '3', 'Hello World');
        $this->assertSame('hello-world-3', $slug3);
    }

    public function testRegisterReplacesExistingSlugForEntity(): void
    {
        $this->sr->register('post', '1', 'Old Title');
        $slug = $this->sr->register('post', '1', 'New Title');
        $this->assertSame('new-title', $slug);
        $this->assertSame(1, $this->sr->count('post'));
    }

    public function testRegisterStripsSpecialChars(): void
    {
        $slug = $this->sr->register('post', '1', 'Hello, World! (2026)');
        $this->assertSame('hello-world-2026', $slug);
    }

    public function testRegisterThrowsOnEmptyNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sr->register('', '1', 'text');
    }

    public function testRegisterThrowsOnEmptyText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sr->register('post', '1', '');
    }

    public function testResolveReturnsRecord(): void
    {
        $this->sr->register('post', '1', 'Hello World');
        $row = $this->sr->resolve('post', 'hello-world');
        $this->assertNotNull($row);
        $this->assertSame('1', $row['entity_id']);
    }

    public function testResolveReturnsNullForMissing(): void
    {
        $this->assertNull($this->sr->resolve('post', 'nope'));
    }

    public function testForEntity(): void
    {
        $this->sr->register('post', '42', 'My Post');
        $this->assertSame('my-post', $this->sr->forEntity('post', '42'));
    }

    public function testForEntityReturnsNullWhenNoSlug(): void
    {
        $this->assertNull($this->sr->forEntity('post', '99'));
    }

    public function testRelease(): void
    {
        $this->sr->register('post', '1', 'Hello');
        $this->assertTrue($this->sr->release('post', '1'));
        $this->assertNull($this->sr->forEntity('post', '1'));
    }

    public function testReleaseReturnsFalseWhenNoSlug(): void
    {
        $this->assertFalse($this->sr->release('post', '999'));
    }

    public function testIsTaken(): void
    {
        $this->sr->register('post', '1', 'Hello World');
        $this->assertTrue($this->sr->isTaken('post', 'hello-world'));
        $this->assertFalse($this->sr->isTaken('post', 'nope'));
    }

    public function testNamespacesAreIsolated(): void
    {
        $this->sr->register('post', '1', 'Hello World');
        $slug = $this->sr->register('page', '1', 'Hello World');
        $this->assertSame('hello-world', $slug); // no conflict across namespaces
    }

    public function testCount(): void
    {
        $this->assertSame(0, $this->sr->count('post'));
        $this->sr->register('post', '1', 'a');
        $this->sr->register('post', '2', 'b');
        $this->sr->register('page', '1', 'c');
        $this->assertSame(2, $this->sr->count('post'));
        $this->assertSame(3, $this->sr->count());
    }
}
