<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\KnowledgeBase;
use PDO;
use PHPUnit\Framework\TestCase;

final class KnowledgeBaseTest extends TestCase
{
    private PDO $pdo;
    private KnowledgeBase $kb;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE kb_articles (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                title        VARCHAR(255) NOT NULL,
                body         TEXT         NOT NULL DEFAULT \'\',
                category     VARCHAR(100) NOT NULL DEFAULT \'\',
                status       VARCHAR(20)  NOT NULL DEFAULT \'draft\',
                author_id    VARCHAR(255) NOT NULL DEFAULT \'\',
                views        INTEGER      NOT NULL DEFAULT 0,
                published_at DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->kb = new KnowledgeBase($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->kb->create('How to reset password');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresDraftStatus(): void
    {
        $id  = $this->kb->create('Title', 'Body', 'account', 'author-1');
        $row = $this->kb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(KnowledgeBase::STATUS_DRAFT, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('account', $row['category']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('author-1', $row['author_id']);
    }

    public function testCreateThrowsOnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->kb->create('');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function testUpdateChangesContent(): void
    {
        $id = $this->kb->create('Old', 'Old body');
        $result = $this->kb->update($id, 'New title', 'New body');
        $this->assertTrue($result);

        $row = $this->kb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('New title', $row['title']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('New body', $row['body']);
    }

    public function testUpdateReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->kb->update(9999, 'Title', 'Body'));
    }

    public function testUpdateThrowsOnEmptyTitle(): void
    {
        $id = $this->kb->create('Title');
        $this->expectException(\InvalidArgumentException::class);
        $this->kb->update($id, '', 'Body');
    }

    // ── publish ───────────────────────────────────────────────────────────────

    public function testPublishTransitionsDraftToPublished(): void
    {
        $id = $this->kb->create('Title');
        $result = $this->kb->publish($id);
        $this->assertTrue($result);

        $row = $this->kb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(KnowledgeBase::STATUS_PUBLISHED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['published_at']);
    }

    public function testPublishReturnsFalseForAlreadyPublished(): void
    {
        $id = $this->kb->create('Title');
        $this->kb->publish($id);
        $this->assertFalse($this->kb->publish($id));
    }

    // ── archive ───────────────────────────────────────────────────────────────

    public function testArchiveTransitionsPublishedToArchived(): void
    {
        $id = $this->kb->create('Title');
        $this->kb->publish($id);
        $result = $this->kb->archive($id);
        $this->assertTrue($result);

        $row = $this->kb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(KnowledgeBase::STATUS_ARCHIVED, $row['status']);
    }

    public function testArchiveReturnsFalseForDraft(): void
    {
        $id = $this->kb->create('Title');
        $this->assertFalse($this->kb->archive($id));
    }

    // ── restore ───────────────────────────────────────────────────────────────

    public function testRestoreTransitionsArchivedToDraft(): void
    {
        $id = $this->kb->create('Title');
        $this->kb->publish($id);
        $this->kb->archive($id);
        $result = $this->kb->restore($id);
        $this->assertTrue($result);

        $row = $this->kb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(KnowledgeBase::STATUS_DRAFT, $row['status']);
    }

    public function testRestoreReturnsFalseForPublished(): void
    {
        $id = $this->kb->create('Title');
        $this->kb->publish($id);
        $this->assertFalse($this->kb->restore($id));
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->kb->find(9999));
    }

    // ── published ─────────────────────────────────────────────────────────────

    public function testPublishedReturnsOnlyPublishedArticles(): void
    {
        $id1 = $this->kb->create('Published');
        $this->kb->publish($id1);
        $this->kb->create('Draft');

        $rows = $this->kb->published();
        $this->assertCount(1, $rows);
        $this->assertSame('Published', $rows[0]['title']);
    }

    public function testPublishedFiltersByCategory(): void
    {
        $id1 = $this->kb->create('A', '', 'account');
        $id2 = $this->kb->create('B', '', 'billing');
        $this->kb->publish($id1);
        $this->kb->publish($id2);

        $rows = $this->kb->published('account');
        $this->assertCount(1, $rows);
        $this->assertSame('A', $rows[0]['title']);
    }

    public function testPublishedExcludesArchived(): void
    {
        $id = $this->kb->create('Article');
        $this->kb->publish($id);
        $this->kb->archive($id);
        $this->assertSame([], $this->kb->published());
    }

    // ── search ────────────────────────────────────────────────────────────────

    public function testSearchFindsPublishedByTitle(): void
    {
        $id = $this->kb->create('How to reset password', 'Step 1: click reset.');
        $this->kb->publish($id);

        $results = $this->kb->search('reset');
        $this->assertCount(1, $results);
    }

    public function testSearchFindsPublishedByBody(): void
    {
        $id = $this->kb->create('Account help', 'To change your email address...');
        $this->kb->publish($id);

        $results = $this->kb->search('email address');
        $this->assertCount(1, $results);
    }

    public function testSearchExcludesDrafts(): void
    {
        $this->kb->create('Draft about searching');
        $this->assertSame([], $this->kb->search('searching'));
    }

    public function testSearchReturnsEmptyForBlankKeyword(): void
    {
        $this->assertSame([], $this->kb->search(''));
    }

    // ── recordView ────────────────────────────────────────────────────────────

    public function testRecordViewIncrementsCounter(): void
    {
        $id = $this->kb->create('Title');
        $this->kb->recordView($id);
        $this->kb->recordView($id);

        $row = $this->kb->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['views']);
    }

    public function testRecordViewReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->kb->recordView(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesArticle(): void
    {
        $id = $this->kb->create('Title');
        $result = $this->kb->delete($id);
        $this->assertTrue($result);
        $this->assertNull($this->kb->find($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->kb->delete(9999));
    }
}
