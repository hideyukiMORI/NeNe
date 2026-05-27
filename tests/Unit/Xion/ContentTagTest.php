<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ContentTag;
use PDO;
use PHPUnit\Framework\TestCase;

final class ContentTagTest extends TestCase
{
    private PDO $pdo;
    private ContentTag $ct;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE content_tags (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                tag         VARCHAR(100) NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (entity_type, entity_id, tag)
            )
        ');
        $this->ct = new ContentTag($this->pdo);
    }

    // ── tagOne ────────────────────────────────────────────────────────────────

    public function testTagOneReturnsTrueOnNewTag(): void
    {
        $this->assertTrue($this->ct->tagOne('article', '1', 'php'));
    }

    public function testTagOneReturnsFalseOnDuplicate(): void
    {
        $this->ct->tagOne('article', '1', 'php');
        $this->assertFalse($this->ct->tagOne('article', '1', 'php'));
    }

    public function testTagOneNormalisesToLowercase(): void
    {
        $this->ct->tagOne('article', '1', 'PHP');
        $this->assertTrue($this->ct->hasTag('article', '1', 'php'));
    }

    public function testTagOneNormalisesSpecialChars(): void
    {
        $this->ct->tagOne('article', '1', 'Hello World!');
        $this->assertTrue($this->ct->hasTag('article', '1', 'hello-world'));
    }

    public function testTagOneThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->tagOne('', '1', 'php');
    }

    public function testTagOneThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->tagOne('article', '', 'php');
    }

    public function testTagOneThrowsOnEmptyTag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ct->tagOne('article', '1', '!!!');
    }

    // ── tag ───────────────────────────────────────────────────────────────────

    public function testTagAddsMultiple(): void
    {
        $added = $this->ct->tag('article', '1', ['php', 'testing', 'oop']);
        $this->assertSame(3, $added);
    }

    public function testTagSkipsDuplicates(): void
    {
        $this->ct->tagOne('article', '1', 'php');
        $added = $this->ct->tag('article', '1', ['php', 'testing']);
        $this->assertSame(1, $added);
    }

    // ── untag ─────────────────────────────────────────────────────────────────

    public function testUntagRemovesTag(): void
    {
        $this->ct->tagOne('article', '1', 'php');
        $result = $this->ct->untag('article', '1', 'php');
        $this->assertTrue($result);
        $this->assertFalse($this->ct->hasTag('article', '1', 'php'));
    }

    public function testUntagReturnsFalseWhenTagNotPresent(): void
    {
        $this->assertFalse($this->ct->untag('article', '1', 'php'));
    }

    // ── clearAll ──────────────────────────────────────────────────────────────

    public function testClearAllRemovesAllTagsForEntity(): void
    {
        $this->ct->tag('article', '1', ['a', 'b', 'c']);
        $this->ct->tag('article', '2', ['a']);

        $count = $this->ct->clearAll('article', '1');
        $this->assertSame(3, $count);
        $this->assertSame([], $this->ct->tagsFor('article', '1'));
        $this->assertCount(1, $this->ct->tagsFor('article', '2'));
    }

    public function testClearAllReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->ct->clearAll('article', '99'));
    }

    // ── hasTag ────────────────────────────────────────────────────────────────

    public function testHasTagReturnsTrueWhenTagged(): void
    {
        $this->ct->tagOne('article', '1', 'php');
        $this->assertTrue($this->ct->hasTag('article', '1', 'php'));
    }

    public function testHasTagReturnsFalseWhenNotTagged(): void
    {
        $this->assertFalse($this->ct->hasTag('article', '1', 'php'));
    }

    // ── tagsFor ───────────────────────────────────────────────────────────────

    public function testTagsForReturnsAlphabetical(): void
    {
        $this->ct->tag('article', '1', ['oop', 'php', 'abc']);
        $tags = $this->ct->tagsFor('article', '1');
        $this->assertSame(['abc', 'oop', 'php'], $tags);
    }

    public function testTagsForReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ct->tagsFor('article', '99'));
    }

    public function testTagsForIsIsolatedByEntity(): void
    {
        $this->ct->tagOne('article', '1', 'php');
        $this->ct->tagOne('article', '2', 'java');
        $this->assertSame(['php'], $this->ct->tagsFor('article', '1'));
        $this->assertSame(['java'], $this->ct->tagsFor('article', '2'));
    }

    // ── entitiesWith ─────────────────────────────────────────────────────────

    public function testEntitiesWithReturnsMatchingEntityIds(): void
    {
        $this->ct->tagOne('article', '1', 'php');
        $this->ct->tagOne('article', '2', 'php');
        $this->ct->tagOne('article', '3', 'java');

        $entities = $this->ct->entitiesWith('article', 'php');
        $this->assertCount(2, $entities);
        $this->assertContains('1', $entities);
        $this->assertContains('2', $entities);
    }

    public function testEntitiesWithReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ct->entitiesWith('article', 'python'));
    }

    // ── cloud ─────────────────────────────────────────────────────────────────

    public function testCloudReturnsCounts(): void
    {
        $this->ct->tag('article', '1', ['php', 'oop']);
        $this->ct->tagOne('article', '2', 'php');

        $cloud = $this->ct->cloud('article');
        $this->assertSame(2, $cloud['php']);
        $this->assertSame(1, $cloud['oop']);
    }

    public function testCloudSortsByCountDescending(): void
    {
        $this->ct->tag('article', '1', ['b', 'a']);
        $this->ct->tagOne('article', '2', 'a');

        $keys = array_keys($this->ct->cloud('article'));
        $this->assertSame('a', $keys[0]);
        $this->assertSame('b', $keys[1]);
    }

    public function testCloudReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->ct->cloud('article'));
    }

    public function testCloudIsIsolatedByEntityType(): void
    {
        $this->ct->tagOne('article', '1', 'php');
        $this->ct->tagOne('video', '1', 'java');

        $artCloud = $this->ct->cloud('article');
        $this->assertArrayHasKey('php', $artCloud);
        $this->assertArrayNotHasKey('java', $artCloud);
    }
}
