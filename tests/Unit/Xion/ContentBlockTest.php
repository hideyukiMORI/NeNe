<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ContentBlock;
use PDO;
use PHPUnit\Framework\TestCase;

final class ContentBlockTest extends TestCase
{
    private PDO $pdo;
    private ContentBlock $cb;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE content_blocks (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                block_type  VARCHAR(100) NOT NULL,
                content     TEXT         NOT NULL,
                position    INTEGER      NOT NULL DEFAULT 0,
                active      TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cb = new ContentBlock($this->pdo);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddReturnsId(): void
    {
        $id = $this->cb->add('page', 'home', 'hero', ['title' => 'Welcome'], 1);
        $this->assertGreaterThan(0, $id);
    }

    public function testAddStoresBlock(): void
    {
        $id  = $this->cb->add('page', 'home', 'hero', ['title' => 'Welcome'], 1);
        $row = $this->cb->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('hero', $row['block_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $content = json_decode((string)$row['content'], true);
        $this->assertSame('Welcome', $content['title']);
    }

    public function testAddThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cb->add('', 'home', 'hero', ['title' => 'x']);
    }

    public function testAddThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cb->add('page', '', 'hero', ['title' => 'x']);
    }

    public function testAddThrowsOnEmptyBlockType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cb->add('page', 'home', '', ['title' => 'x']);
    }

    public function testAddThrowsOnEmptyContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cb->add('page', 'home', 'hero', []);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->cb->get(9999));
    }

    // ── blocks ────────────────────────────────────────────────────────────────

    public function testBlocksReturnsActiveOrdered(): void
    {
        $this->cb->add('page', 'home', 'text', ['body' => 'second'], 2);
        $this->cb->add('page', 'home', 'hero', ['title' => 'first'], 1);
        $list = $this->cb->blocks('page', 'home');
        $this->assertCount(2, $list);
        $this->assertSame('hero', $list[0]['block_type']);
    }

    public function testBlocksExcludesInactive(): void
    {
        $id1 = $this->cb->add('page', 'home', 'hero', ['title' => 'x'], 1);
        $this->cb->add('page', 'home', 'text', ['body' => 'y'], 2);
        $this->cb->deactivate($id1);
        $list = $this->cb->blocks('page', 'home');
        $this->assertCount(1, $list);
        $this->assertSame('text', $list[0]['block_type']);
    }

    public function testBlocksReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->cb->blocks('page', 'missing'));
    }

    public function testBlocksIsIsolatedByEntity(): void
    {
        $this->cb->add('page', 'home', 'hero', ['title' => 'x'], 1);
        $this->cb->add('page', 'about', 'hero', ['title' => 'y'], 1);
        $this->assertCount(1, $this->cb->blocks('page', 'home'));
        $this->assertCount(1, $this->cb->blocks('page', 'about'));
    }

    // ── allBlocks ─────────────────────────────────────────────────────────────

    public function testAllBlocksIncludesInactive(): void
    {
        $id = $this->cb->add('page', 'home', 'hero', ['title' => 'x'], 1);
        $this->cb->deactivate($id);
        $list = $this->cb->allBlocks('page', 'home');
        $this->assertCount(1, $list);
        $this->assertSame(0, (int)$list[0]['active']);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function testUpdateChangesContent(): void
    {
        $id = $this->cb->add('page', 'home', 'text', ['body' => 'old'], 1);
        $this->assertTrue($this->cb->update($id, ['body' => 'new']));
        $row = $this->cb->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $content = json_decode((string)$row['content'], true);
        $this->assertSame('new', $content['body']);
    }

    public function testUpdateReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cb->update(9999, ['body' => 'x']));
    }

    public function testUpdateThrowsOnEmptyContent(): void
    {
        $id = $this->cb->add('page', 'home', 'text', ['body' => 'x'], 1);
        $this->expectException(\InvalidArgumentException::class);
        $this->cb->update($id, []);
    }

    // ── reorder ───────────────────────────────────────────────────────────────

    public function testReorderChangesPosition(): void
    {
        $id = $this->cb->add('page', 'home', 'hero', ['title' => 'x'], 3);
        $this->assertTrue($this->cb->reorder($id, 1));
        $row = $this->cb->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['position']);
    }

    public function testReorderReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cb->reorder(9999, 1));
    }

    // ── deactivate / activate ─────────────────────────────────────────────────

    public function testDeactivateHidesBlock(): void
    {
        $id = $this->cb->add('page', 'home', 'hero', ['title' => 'x'], 1);
        $this->assertTrue($this->cb->deactivate($id));
        $this->assertCount(0, $this->cb->blocks('page', 'home'));
    }

    public function testActivateRestoresBlock(): void
    {
        $id = $this->cb->add('page', 'home', 'hero', ['title' => 'x'], 1);
        $this->cb->deactivate($id);
        $this->assertTrue($this->cb->activate($id));
        $this->assertCount(1, $this->cb->blocks('page', 'home'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesBlock(): void
    {
        $id = $this->cb->add('page', 'home', 'hero', ['title' => 'x'], 1);
        $this->assertTrue($this->cb->remove($id));
        $this->assertNull($this->cb->get($id));
    }

    public function testRemoveReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cb->remove(9999));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearDeletesAllBlocksForEntity(): void
    {
        $this->cb->add('page', 'home', 'hero', ['title' => 'x'], 1);
        $this->cb->add('page', 'home', 'text', ['body' => 'y'], 2);
        $this->cb->add('page', 'about', 'hero', ['title' => 'z'], 1);
        $deleted = $this->cb->clear('page', 'home');
        $this->assertSame(2, $deleted);
        $this->assertSame([], $this->cb->blocks('page', 'home'));
        $this->assertCount(1, $this->cb->blocks('page', 'about'));
    }
}
