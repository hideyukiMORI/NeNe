<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\ContentDraft;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentDraft using an in-memory SQLite database.
 */
final class ContentDraftTest extends TestCase
{
    private PDO $db;
    private ContentDraft $content;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE contents (
                id           INTEGER      PRIMARY KEY AUTOINCREMENT,
                author_id    VARCHAR(255) NOT NULL,
                title        VARCHAR(255) NOT NULL,
                body         TEXT         NOT NULL DEFAULT \'\',
                status       VARCHAR(16)  NOT NULL DEFAULT \'draft\',
                published_at DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->content = new ContentDraft($this->db);
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->content->create('user:1', 'Title', 'Body');
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreatedContentIsDraft(): void
    {
        $id   = $this->content->create('user:1', 'Title');
        $item = $this->content->find($id, 'user:1');
        $this->assertSame(ContentDraft::STATUS_DRAFT, $item['status']);
    }

    public function testCreatedContentAuthor(): void
    {
        $id   = $this->content->create('user:1', 'Title', 'Body');
        $item = $this->content->find($id, 'user:1');
        $this->assertSame('user:1', $item['author_id']);
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function testUpdateDraftByAuthorReturnsTrue(): void
    {
        $id     = $this->content->create('user:1', 'Old Title');
        $result = $this->content->update($id, 'user:1', 'New Title', 'New Body');
        $this->assertTrue($result);
    }

    public function testUpdateDraftChangesTitle(): void
    {
        $id = $this->content->create('user:1', 'Old Title');
        $this->content->update($id, 'user:1', 'New Title', 'Body');
        $item = $this->content->find($id, 'user:1');
        $this->assertSame('New Title', $item['title']);
    }

    public function testUpdateByWrongAuthorReturnsFalse(): void
    {
        $id     = $this->content->create('user:1', 'Title');
        $result = $this->content->update($id, 'user:2', 'Hacked', 'Body');
        $this->assertFalse($result);
    }

    public function testUpdatePublishedReturnsFalse(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->content->publish($id, 'user:1');
        $result = $this->content->update($id, 'user:1', 'Changed', 'Body');
        $this->assertFalse($result);
    }

    // ── publish ───────────────────────────────────────────────────────────────

    public function testPublishDraftByAuthorReturnsTrue(): void
    {
        $id     = $this->content->create('user:1', 'Title');
        $result = $this->content->publish($id, 'user:1');
        $this->assertTrue($result);
    }

    public function testPublishSetsStatusToPublished(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->content->publish($id, 'user:1');
        $item = $this->content->find($id);
        $this->assertSame(ContentDraft::STATUS_PUBLISHED, $item['status']);
    }

    public function testPublishSetsPublishedAt(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->content->publish($id, 'user:1');
        $item = $this->content->find($id);
        $this->assertNotNull($item['published_at']);
    }

    public function testPublishByWrongAuthorReturnsFalse(): void
    {
        $id     = $this->content->create('user:1', 'Title');
        $result = $this->content->publish($id, 'user:2');
        $this->assertFalse($result);
    }

    public function testPublishAlreadyPublishedReturnsFalse(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->content->publish($id, 'user:1');
        $result = $this->content->publish($id, 'user:1'); // already published
        $this->assertFalse($result);
    }

    // ── archive ───────────────────────────────────────────────────────────────

    public function testArchivePublishedByAuthorReturnsTrue(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->content->publish($id, 'user:1');
        $result = $this->content->archive($id, 'user:1');
        $this->assertTrue($result);
    }

    public function testArchiveSetsStatusToArchived(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->content->publish($id, 'user:1');
        $this->content->archive($id, 'user:1');
        // Author can still see it
        $item = $this->content->find($id, 'user:1');
        $this->assertSame(ContentDraft::STATUS_ARCHIVED, $item['status']);
    }

    public function testArchiveDraftReturnsFalse(): void
    {
        $id     = $this->content->create('user:1', 'Title');
        $result = $this->content->archive($id, 'user:1'); // draft → archived not allowed
        $this->assertFalse($result);
    }

    public function testArchiveByWrongAuthorReturnsFalse(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->content->publish($id, 'user:1');
        $result = $this->content->archive($id, 'user:2');
        $this->assertFalse($result);
    }

    // ── find ─────────────────────────────────────────────────────────────────

    public function testFindPublishedByAnyoneReturnsItem(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->content->publish($id, 'user:1');
        $this->assertNotNull($this->content->find($id));
        $this->assertNotNull($this->content->find($id, 'user:2'));
        $this->assertNotNull($this->content->find($id, null));
    }

    public function testFindDraftByNonAuthorReturnsNull(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->assertNull($this->content->find($id, 'user:2'));
        $this->assertNull($this->content->find($id, null));
    }

    public function testFindDraftByAuthorReturnsItem(): void
    {
        $id   = $this->content->create('user:1', 'Title');
        $item = $this->content->find($id, 'user:1');
        $this->assertNotNull($item);
        $this->assertSame('Title', $item['title']);
    }

    public function testFindArchivedByNonAuthorReturnsNull(): void
    {
        $id = $this->content->create('user:1', 'Title');
        $this->content->publish($id, 'user:1');
        $this->content->archive($id, 'user:1');
        $this->assertNull($this->content->find($id, 'user:2'));
    }

    public function testFindNotFoundReturnsNull(): void
    {
        $this->assertNull($this->content->find(9999));
    }

    // ── listPublished ─────────────────────────────────────────────────────────

    public function testListPublishedReturnsOnlyPublished(): void
    {
        $id1 = $this->content->create('user:1', 'Draft');
        $id2 = $this->content->create('user:1', 'Published');
        $this->content->publish($id2, 'user:1');

        $items = $this->content->listPublished();
        $this->assertCount(1, $items);
        $this->assertSame('Published', $items[0]['title']);
    }

    public function testListPublishedOrderedNewestFirst(): void
    {
        $id1 = $this->content->create('user:1', 'First');
        $this->content->publish($id1, 'user:1');
        $id2 = $this->content->create('user:1', 'Second');
        $this->content->publish($id2, 'user:1');

        $items = $this->content->listPublished();
        // id2 was published after id1 (higher id → newer)
        $this->assertSame('Second', $items[0]['title']);
    }

    public function testListPublishedExcludesDraftsAndArchived(): void
    {
        $id1 = $this->content->create('user:1', 'Draft');
        $id2 = $this->content->create('user:1', 'Published');
        $id3 = $this->content->create('user:1', 'ToArchive');
        $this->content->publish($id2, 'user:1');
        $this->content->publish($id3, 'user:1');
        $this->content->archive($id3, 'user:1');

        $items = $this->content->listPublished();
        $this->assertCount(1, $items);
        $this->assertSame('Published', $items[0]['title']);
    }
}
