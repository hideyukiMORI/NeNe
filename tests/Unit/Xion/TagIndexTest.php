<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\TagIndex;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TagIndex.
 */
final class TagIndexTest extends TestCase
{
    private PDO $db;
    private TagIndex $ti;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE tag_index (
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                tag         VARCHAR(100) NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (entity_type, entity_id, tag)
            )
        ');
        $this->ti = new TagIndex($this->db);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddStoresTags(): void
    {
        $this->ti->add('post', '1', ['php', 'web']);
        $this->assertSame(['php', 'web'], $this->ti->tags('post', '1'));
    }

    public function testAddNormalisesToLowercase(): void
    {
        $this->ti->add('post', '1', ['PHP', 'Web']);
        $this->assertSame(['php', 'web'], $this->ti->tags('post', '1'));
    }

    public function testAddIsIdempotent(): void
    {
        $this->ti->add('post', '1', ['php']);
        $this->ti->add('post', '1', ['php']); // no exception
        $this->assertCount(1, $this->ti->tags('post', '1'));
    }

    public function testAddDeduplicatesWithinCall(): void
    {
        $this->ti->add('post', '1', ['php', 'php', 'PHP']);
        $this->assertCount(1, $this->ti->tags('post', '1'));
    }

    public function testAddThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ti->add('', '1', ['php']);
    }

    public function testAddThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ti->add('post', '', ['php']);
    }

    public function testAddThrowsOnEmptyTagsAfterNormalisation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ti->add('post', '1', ['  ', '']);
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesTag(): void
    {
        $this->ti->add('post', '1', ['php', 'web']);
        $this->assertTrue($this->ti->remove('post', '1', 'php'));
        $this->assertSame(['web'], $this->ti->tags('post', '1'));
    }

    public function testRemoveReturnsFalseIfTagNotPresent(): void
    {
        $this->ti->add('post', '1', ['php']);
        $this->assertFalse($this->ti->remove('post', '1', 'java'));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearRemovesAllTags(): void
    {
        $this->ti->add('post', '1', ['php', 'web']);
        $this->assertSame(2, $this->ti->clear('post', '1'));
        $this->assertSame([], $this->ti->tags('post', '1'));
    }

    public function testClearDoesNotAffectOtherEntities(): void
    {
        $this->ti->add('post', '1', ['php']);
        $this->ti->add('post', '2', ['php']);
        $this->ti->clear('post', '1');
        $this->assertSame(['php'], $this->ti->tags('post', '2'));
    }

    // ── set ───────────────────────────────────────────────────────────────────

    public function testSetReplacesExistingTags(): void
    {
        $this->ti->add('post', '1', ['php', 'web']);
        $this->ti->set('post', '1', ['python']);
        $this->assertSame(['python'], $this->ti->tags('post', '1'));
    }

    public function testSetWithEmptyArrayClearsAllTags(): void
    {
        $this->ti->add('post', '1', ['php']);
        $this->ti->set('post', '1', []);
        $this->assertSame([], $this->ti->tags('post', '1'));
    }

    // ── hasTag ────────────────────────────────────────────────────────────────

    public function testHasTagReturnsTrueForExistingTag(): void
    {
        $this->ti->add('post', '1', ['php']);
        $this->assertTrue($this->ti->hasTag('post', '1', 'PHP')); // case insensitive
    }

    public function testHasTagReturnsFalseForMissingTag(): void
    {
        $this->assertFalse($this->ti->hasTag('post', '1', 'php'));
    }

    // ── byTag ─────────────────────────────────────────────────────────────────

    public function testByTagReturnsMatchingEntities(): void
    {
        $this->ti->add('post', '1', ['php']);
        $this->ti->add('post', '2', ['php', 'web']);
        $this->ti->add('post', '3', ['java']);
        $this->assertSame(['1', '2'], $this->ti->byTag('post', 'php'));
    }

    public function testByTagIsEntityTypeScoped(): void
    {
        $this->ti->add('post', '1', ['php']);
        $this->ti->add('video', '1', ['php']);
        $this->assertCount(1, $this->ti->byTag('post', 'php'));
    }

    // ── byAllTags ─────────────────────────────────────────────────────────────

    public function testByAllTagsReturnsEntitiesWithAllTags(): void
    {
        $this->ti->add('post', '1', ['php', 'web']);
        $this->ti->add('post', '2', ['php']);
        $this->ti->add('post', '3', ['web']);
        $result = $this->ti->byAllTags('post', ['php', 'web']);
        $this->assertSame(['1'], $result);
    }

    public function testByAllTagsReturnsEmptyForEmptyTagList(): void
    {
        $this->ti->add('post', '1', ['php']);
        $this->assertSame([], $this->ti->byAllTags('post', []));
    }

    // ── countByTag ────────────────────────────────────────────────────────────

    public function testCountByTagReturnsCorrectCount(): void
    {
        $this->ti->add('post', '1', ['php']);
        $this->ti->add('post', '2', ['php']);
        $this->ti->add('post', '3', ['java']);
        $this->assertSame(2, $this->ti->countByTag('post', 'php'));
    }

    // ── popularTags ───────────────────────────────────────────────────────────

    public function testPopularTagsReturnsSortedByCount(): void
    {
        $this->ti->add('post', '1', ['php', 'web']);
        $this->ti->add('post', '2', ['php']);
        $popular = $this->ti->popularTags('post', 10);
        $this->assertSame('php', $popular[0]['tag']);
        $this->assertSame(2, $popular[0]['count']);
        $this->assertSame('web', $popular[1]['tag']);
    }

    public function testPopularTagsReturnsEmptyForNoTags(): void
    {
        $this->assertSame([], $this->ti->popularTags('post'));
    }
}
