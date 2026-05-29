<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Checklist;
use PDO;
use PHPUnit\Framework\TestCase;

final class ChecklistTest extends TestCase
{
    private PDO $pdo;
    private Checklist $cl;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE checklist_items (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type  VARCHAR(100) NOT NULL,
                entity_id    VARCHAR(255) NOT NULL,
                label        TEXT         NOT NULL,
                position     INTEGER      NOT NULL DEFAULT 0,
                completed    TINYINT(1)   NOT NULL DEFAULT 0,
                completed_at DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->cl = new Checklist($this->pdo);
    }

    // ── addItem ───────────────────────────────────────────────────────────────

    public function testAddItemReturnsId(): void
    {
        $id = $this->cl->addItem('issue', '42', 'Write tests', 1);
        $this->assertGreaterThan(0, $id);
    }

    public function testAddItemStoresCorrectly(): void
    {
        $id    = $this->cl->addItem('issue', '42', 'Write tests', 1);
        $items = $this->cl->items('issue', '42');
        $this->assertCount(1, $items);
        $this->assertSame($id, (int)$items[0]['id']);
        $this->assertSame('Write tests', $items[0]['label']);
        $this->assertSame(1, (int)$items[0]['position']);
        $this->assertSame(0, (int)$items[0]['completed']);
    }

    public function testAddItemTrimsEntityTypeAndId(): void
    {
        $this->cl->addItem('  issue  ', '  42  ', 'Label');
        $items = $this->cl->items('issue', '42');
        $this->assertCount(1, $items);
    }

    public function testAddItemThrowsOnEmptyLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->addItem('issue', '1', '   ');
    }

    public function testAddItemThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->addItem('', '1', 'Label');
    }

    public function testAddItemThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->addItem('issue', '', 'Label');
    }

    // ── check / uncheck ───────────────────────────────────────────────────────

    public function testCheckMarksCompleted(): void
    {
        $id = $this->cl->addItem('issue', '42', 'Step 1');
        $result = $this->cl->check($id);
        $this->assertTrue($result);

        $items = $this->cl->items('issue', '42');
        $this->assertSame(1, (int)$items[0]['completed']);
        $this->assertNotNull($items[0]['completed_at']);
    }

    public function testUncheckClearsCompleted(): void
    {
        $id = $this->cl->addItem('issue', '42', 'Step 1');
        $this->cl->check($id);
        $result = $this->cl->uncheck($id);
        $this->assertTrue($result);

        $items = $this->cl->items('issue', '42');
        $this->assertSame(0, (int)$items[0]['completed']);
        $this->assertNull($items[0]['completed_at']);
    }

    public function testCheckReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cl->check(9999));
    }

    public function testUncheckReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cl->uncheck(9999));
    }

    // ── updateLabel ───────────────────────────────────────────────────────────

    public function testUpdateLabelChangesLabel(): void
    {
        $id = $this->cl->addItem('issue', '1', 'Old label');
        $result = $this->cl->updateLabel($id, 'New label');
        $this->assertTrue($result);

        $items = $this->cl->items('issue', '1');
        $this->assertSame('New label', $items[0]['label']);
    }

    public function testUpdateLabelThrowsOnEmptyLabel(): void
    {
        $id = $this->cl->addItem('issue', '1', 'Label');
        $this->expectException(\InvalidArgumentException::class);
        $this->cl->updateLabel($id, '');
    }

    public function testUpdateLabelReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cl->updateLabel(9999, 'Label'));
    }

    // ── removeItem ────────────────────────────────────────────────────────────

    public function testRemoveItemDeletesRow(): void
    {
        $id = $this->cl->addItem('issue', '1', 'Label');
        $result = $this->cl->removeItem($id);
        $this->assertTrue($result);
        $this->assertCount(0, $this->cl->items('issue', '1'));
    }

    public function testRemoveItemReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->cl->removeItem(9999));
    }

    // ── clear ─────────────────────────────────────────────────────────────────

    public function testClearDeletesAllForEntity(): void
    {
        $this->cl->addItem('issue', '5', 'A', 1);
        $this->cl->addItem('issue', '5', 'B', 2);
        $this->cl->addItem('issue', '6', 'C', 1);

        $count = $this->cl->clear('issue', '5');
        $this->assertSame(2, $count);
        $this->assertCount(0, $this->cl->items('issue', '5'));
        $this->assertCount(1, $this->cl->items('issue', '6'));
    }

    public function testClearReturnsZeroWhenNoItems(): void
    {
        $this->assertSame(0, $this->cl->clear('issue', '99'));
    }

    // ── items ─────────────────────────────────────────────────────────────────

    public function testItemsOrdersByPositionThenId(): void
    {
        $id3 = $this->cl->addItem('task', '1', 'Third', 3);
        $id1 = $this->cl->addItem('task', '1', 'First', 1);
        $id2 = $this->cl->addItem('task', '1', 'Second', 2);

        $items = $this->cl->items('task', '1');
        $this->assertCount(3, $items);
        $this->assertSame($id1, (int)$items[0]['id']);
        $this->assertSame($id2, (int)$items[1]['id']);
        $this->assertSame($id3, (int)$items[2]['id']);
    }

    public function testItemsReturnsEmptyArrayWhenNone(): void
    {
        $this->assertSame([], $this->cl->items('issue', '999'));
    }

    public function testItemsIsolatedByEntity(): void
    {
        $this->cl->addItem('issue', '1', 'A');
        $this->cl->addItem('issue', '2', 'B');

        $this->assertCount(1, $this->cl->items('issue', '1'));
        $this->assertCount(1, $this->cl->items('issue', '2'));
    }

    // ── pending ───────────────────────────────────────────────────────────────

    public function testPendingReturnsOnlyUncompleted(): void
    {
        $id1 = $this->cl->addItem('issue', '10', 'Done', 1);
        $id2 = $this->cl->addItem('issue', '10', 'Todo', 2);
        $this->cl->check($id1);

        $pending = $this->cl->pending('issue', '10');
        $this->assertCount(1, $pending);
        $this->assertSame($id2, (int)$pending[0]['id']);
    }

    public function testPendingReturnsEmptyWhenAllDone(): void
    {
        $id = $this->cl->addItem('issue', '10', 'Done');
        $this->cl->check($id);
        $this->assertSame([], $this->cl->pending('issue', '10'));
    }

    // ── percent ───────────────────────────────────────────────────────────────

    public function testPercentReturnsZeroWhenNoItems(): void
    {
        $this->assertSame(0, $this->cl->percent('issue', '99'));
    }

    public function testPercentAllUncompleted(): void
    {
        $this->cl->addItem('issue', '20', 'A');
        $this->cl->addItem('issue', '20', 'B');
        $this->assertSame(0, $this->cl->percent('issue', '20'));
    }

    public function testPercentAllCompleted(): void
    {
        $id1 = $this->cl->addItem('issue', '20', 'A');
        $id2 = $this->cl->addItem('issue', '20', 'B');
        $this->cl->check($id1);
        $this->cl->check($id2);
        $this->assertSame(100, $this->cl->percent('issue', '20'));
    }

    public function testPercentHalf(): void
    {
        $id1 = $this->cl->addItem('issue', '30', 'A');
        $this->cl->addItem('issue', '30', 'B');
        $this->cl->check($id1);
        $this->assertSame(50, $this->cl->percent('issue', '30'));
    }

    public function testPercentRoundsCorrectly(): void
    {
        $id1 = $this->cl->addItem('issue', '40', 'A');
        $this->cl->addItem('issue', '40', 'B');
        $this->cl->addItem('issue', '40', 'C');
        $this->cl->check($id1);
        // 1/3 = 33.33% → rounds to 33
        $this->assertSame(33, $this->cl->percent('issue', '40'));
    }
}
