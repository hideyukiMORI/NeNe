<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\TagManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TagManager using an in-memory SQLite database.
 */
final class TagManagerTest extends TestCase
{
    private PDO $db;
    private TagManager $tags;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE tags (
                id         INTEGER      PRIMARY KEY AUTOINCREMENT,
                name       VARCHAR(100) NOT NULL UNIQUE,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'CREATE TABLE tag_attachments (
                tag_id      INTEGER      NOT NULL,
                entity_type VARCHAR(64)  NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (tag_id, entity_type, entity_id)
            )'
        );
        $this->tags = new TagManager($this->db);
    }

    // ── attach ────────────────────────────────────────────────────────────────

    public function testAttachCreatesTagsAndReturnsThemForEntity(): void
    {
        $this->tags->attach('post', '1', ['php', 'oop']);
        $this->assertSame(['oop', 'php'], $this->tags->tagsFor('post', '1'));
    }

    public function testAttachIsIdempotent(): void
    {
        $this->tags->attach('post', '1', ['php']);
        $this->tags->attach('post', '1', ['php']);
        $this->assertCount(1, $this->tags->tagsFor('post', '1'));
    }

    public function testAttachReusesExistingTag(): void
    {
        $this->tags->attach('post', '1', ['php']);
        $this->tags->attach('post', '2', ['php']);
        // Both posts share the same tag row
        $stmt = $this->db->query('SELECT COUNT(*) FROM tags WHERE name = \'php\'');
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testAttachEmptyArrayDoesNothing(): void
    {
        $this->tags->attach('post', '1', []);
        $this->assertEmpty($this->tags->tagsFor('post', '1'));
    }

    // ── detach ────────────────────────────────────────────────────────────────

    public function testDetachReturnsTrueWhenTagWasAttached(): void
    {
        $this->tags->attach('post', '1', ['php']);
        $this->assertTrue($this->tags->detach('post', '1', 'php'));
    }

    public function testDetachReturnsFalseWhenTagWasNotAttached(): void
    {
        $this->assertFalse($this->tags->detach('post', '1', 'php'));
    }

    public function testDetachRemovesTag(): void
    {
        $this->tags->attach('post', '1', ['php', 'oop']);
        $this->tags->detach('post', '1', 'oop');
        $this->assertSame(['php'], $this->tags->tagsFor('post', '1'));
    }

    // ── syncTags ──────────────────────────────────────────────────────────────

    public function testSyncTagsReplacesExistingTags(): void
    {
        $this->tags->attach('post', '1', ['php', 'oop']);
        $this->tags->syncTags('post', '1', ['solid', 'tdd']);
        $this->assertSame(['solid', 'tdd'], $this->tags->tagsFor('post', '1'));
    }

    public function testSyncTagsWithEmptyArrayRemovesAllTags(): void
    {
        $this->tags->attach('post', '1', ['php']);
        $this->tags->syncTags('post', '1', []);
        $this->assertEmpty($this->tags->tagsFor('post', '1'));
    }

    public function testSyncTagsOnlyAffectsTargetEntity(): void
    {
        $this->tags->attach('post', '1', ['php']);
        $this->tags->attach('post', '2', ['php']);
        $this->tags->syncTags('post', '1', ['solid']);
        // post:2 should be unchanged
        $this->assertSame(['php'], $this->tags->tagsFor('post', '2'));
    }

    // ── tagsFor ───────────────────────────────────────────────────────────────

    public function testTagsForReturnsAlphabeticallySorted(): void
    {
        $this->tags->attach('post', '1', ['zzz', 'aaa', 'mmm']);
        $this->assertSame(['aaa', 'mmm', 'zzz'], $this->tags->tagsFor('post', '1'));
    }

    public function testTagsForReturnsEmptyForUntaggedEntity(): void
    {
        $this->assertEmpty($this->tags->tagsFor('post', '1'));
    }

    public function testTagsForIsEntityTypeIsolated(): void
    {
        $this->tags->attach('post', '1', ['php']);
        $this->assertEmpty($this->tags->tagsFor('product', '1'));
    }

    // ── entitiesWithTag ───────────────────────────────────────────────────────

    public function testEntitiesWithTagReturnsEntityIds(): void
    {
        $this->tags->attach('post', '1', ['php']);
        $this->tags->attach('post', '2', ['php']);
        $this->assertSame(['1', '2'], $this->tags->entitiesWithTag('php', 'post'));
    }

    public function testEntitiesWithTagFiltersByEntityType(): void
    {
        $this->tags->attach('post', '1', ['php']);
        $this->tags->attach('product', '99', ['php']);
        $this->assertSame(['1'], $this->tags->entitiesWithTag('php', 'post'));
    }

    public function testEntitiesWithTagWithoutTypeFilterReturnsAll(): void
    {
        $this->tags->attach('post', '1', ['php']);
        $this->tags->attach('product', '99', ['php']);
        $entities = $this->tags->entitiesWithTag('php');
        $this->assertCount(2, $entities);
    }

    public function testEntitiesWithTagReturnsEmptyForUnusedTag(): void
    {
        $this->assertEmpty($this->tags->entitiesWithTag('nonexistent', 'post'));
    }
}
