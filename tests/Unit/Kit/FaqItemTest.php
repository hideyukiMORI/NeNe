<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\FaqItem;
use PDO;
use PHPUnit\Framework\TestCase;

final class FaqItemTest extends TestCase
{
    private PDO $pdo;
    private FaqItem $faq;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE faq_items (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                category    VARCHAR(100) NOT NULL DEFAULT \'general\',
                question    TEXT         NOT NULL,
                answer      TEXT         NOT NULL,
                position    INTEGER      NOT NULL DEFAULT 0,
                published   TINYINT(1)   NOT NULL DEFAULT 1,
                helpful     INTEGER      NOT NULL DEFAULT 0,
                not_helpful INTEGER      NOT NULL DEFAULT 0,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->faq = new FaqItem($this->pdo);
    }

    // ── add ───────────────────────────────────────────────────────────────────

    public function testAddReturnsId(): void
    {
        $id = $this->faq->add('billing', 'How to cancel?', 'Go to Settings.');
        $this->assertGreaterThan(0, $id);
    }

    public function testAddStoresCorrectly(): void
    {
        $id  = $this->faq->add('billing', 'How to cancel?', 'Go to Settings.', 2);
        $row = $this->faq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('billing', $row['category']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('How to cancel?', $row['question']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['position']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['published']); // published by default
    }

    public function testAddUsesGeneralCategoryByDefault(): void
    {
        $id  = $this->faq->add('', 'Q?', 'A.');
        $row = $this->faq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('general', $row['category']);
    }

    public function testAddThrowsOnEmptyQuestion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->faq->add('cat', '', 'Answer.');
    }

    public function testAddThrowsOnEmptyAnswer(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->faq->add('cat', 'Question?', '');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function testUpdateChangesQuestionAndAnswer(): void
    {
        $id = $this->faq->add('cat', 'Old?', 'Old answer.');
        $this->assertTrue($this->faq->update($id, 'New?', 'New answer.'));

        $row = $this->faq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('New?', $row['question']);
    }

    public function testUpdateReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->faq->update(9999, 'Q?', 'A.'));
    }

    // ── publish / unpublish ───────────────────────────────────────────────────

    public function testUnpublishHidesItem(): void
    {
        $id = $this->faq->add('cat', 'Q?', 'A.');
        $this->faq->unpublish($id);
        $items = $this->faq->forCategory('cat');
        $this->assertCount(0, $items);
    }

    public function testPublishRestoresItem(): void
    {
        $id = $this->faq->add('cat', 'Q?', 'A.');
        $this->faq->unpublish($id);
        $this->faq->publish($id);
        $items = $this->faq->forCategory('cat');
        $this->assertCount(1, $items);
    }

    public function testPublishReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->faq->publish(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesItem(): void
    {
        $id = $this->faq->add('cat', 'Q?', 'A.');
        $this->assertTrue($this->faq->delete($id));
        $this->assertNull($this->faq->find($id));
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->faq->delete(9999));
    }

    // ── forCategory ───────────────────────────────────────────────────────────

    public function testForCategoryOrdersByPositionThenId(): void
    {
        $id3 = $this->faq->add('cat', 'C', 'c', 3);
        $id1 = $this->faq->add('cat', 'A', 'a', 1);
        $id2 = $this->faq->add('cat', 'B', 'b', 2);

        $items = $this->faq->forCategory('cat');
        $this->assertSame($id1, (int)$items[0]['id']);
        $this->assertSame($id2, (int)$items[1]['id']);
        $this->assertSame($id3, (int)$items[2]['id']);
    }

    public function testForCategoryWithPublishedOnlyFalse(): void
    {
        $id = $this->faq->add('cat', 'Q?', 'A.');
        $this->faq->unpublish($id);
        $items = $this->faq->forCategory('cat', false);
        $this->assertCount(1, $items);
    }

    public function testForCategoryReturnsEmptyForUnknownCategory(): void
    {
        $this->assertSame([], $this->faq->forCategory('unknown'));
    }

    // ── allCategories ─────────────────────────────────────────────────────────

    public function testAllCategoriesReturnsSortedDistinctList(): void
    {
        $this->faq->add('billing', 'Q?', 'A.');
        $this->faq->add('billing', 'R?', 'B.');
        $this->faq->add('account', 'S?', 'C.');

        $cats = $this->faq->allCategories();
        $this->assertSame(['account', 'billing'], $cats);
    }

    // ── search ────────────────────────────────────────────────────────────────

    public function testSearchFindsInQuestion(): void
    {
        $id = $this->faq->add('cat', 'How to reset password?', 'Click forgot password.');
        $rows = $this->faq->search('reset');
        $this->assertCount(1, $rows);
        $this->assertSame($id, (int)$rows[0]['id']);
    }

    public function testSearchFindsInAnswer(): void
    {
        $id = $this->faq->add('cat', 'Login problem?', 'Contact support via email.');
        $rows = $this->faq->search('support');
        $this->assertCount(1, $rows);
    }

    public function testSearchDoesNotReturnUnpublished(): void
    {
        $id = $this->faq->add('cat', 'Hidden FAQ?', 'Hidden answer.');
        $this->faq->unpublish($id);
        $rows = $this->faq->search('Hidden');
        $this->assertCount(0, $rows);
    }

    public function testSearchReturnsEmptyWhenNoMatch(): void
    {
        $this->faq->add('cat', 'Q?', 'A.');
        $this->assertSame([], $this->faq->search('zzz_no_match'));
    }

    // ── reorder ───────────────────────────────────────────────────────────────

    public function testReorderChangesPosition(): void
    {
        $id = $this->faq->add('cat', 'Q?', 'A.', 1);
        $this->assertTrue($this->faq->reorder($id, 10));

        $row = $this->faq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(10, (int)$row['position']);
    }

    // ── voteHelpful / voteNotHelpful ──────────────────────────────────────────

    public function testVoteHelpfulIncrementsCounter(): void
    {
        $id = $this->faq->add('cat', 'Q?', 'A.');
        $this->faq->voteHelpful($id);
        $this->faq->voteHelpful($id);

        $row = $this->faq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$row['helpful']);
    }

    public function testVoteNotHelpfulIncrementsCounter(): void
    {
        $id = $this->faq->add('cat', 'Q?', 'A.');
        $this->faq->voteNotHelpful($id);

        $row = $this->faq->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['not_helpful']);
    }

    public function testVoteHelpfulReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->faq->voteHelpful(9999));
    }
}
