<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Label;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Label.
 */
final class LabelTest extends TestCase
{
    private PDO $db;
    private Label $lbl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE labels (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       VARCHAR(100) NOT NULL UNIQUE,
                color      VARCHAR(20)  NOT NULL DEFAULT \'\',
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE label_assignments (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                label_id    INTEGER      NOT NULL,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                assigned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (label_id, entity_type, entity_id)
            );
        ');
        $this->lbl = new Label($this->db);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsPositiveId(): void
    {
        $id = $this->lbl->create('bug', '#e11d48');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresNameAndColor(): void
    {
        $id  = $this->lbl->create('bug', '#e11d48');
        $lbl = $this->lbl->find($id);
        $this->assertNotNull($lbl);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('bug', $lbl['name']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('#e11d48', $lbl['color']);
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lbl->create('');
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function testUpdateChangesNameAndColor(): void
    {
        $id = $this->lbl->create('bug', '#red');
        $this->assertTrue($this->lbl->update($id, 'defect', '#e11d48'));
        $lbl = $this->lbl->find($id);
        $this->assertNotNull($lbl);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('defect', $lbl['name']);
    }

    public function testUpdateReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->lbl->update(999, 'x'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesLabelAndAssignments(): void
    {
        $id = $this->lbl->create('bug');
        $this->lbl->attach('issue', '1', $id);
        $this->assertTrue($this->lbl->delete($id));
        $this->assertNull($this->lbl->find($id));
        $this->assertFalse($this->lbl->hasLabel('issue', '1', $id));
    }

    public function testDeleteReturnsFalseForUnknownId(): void
    {
        $this->assertFalse($this->lbl->delete(999));
    }

    // ── findByName ────────────────────────────────────────────────────────────

    public function testFindByNameReturnsLabel(): void
    {
        $id  = $this->lbl->create('feature');
        $lbl = $this->lbl->findByName('feature');
        $this->assertNotNull($lbl);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id, (int)$lbl['id']);
    }

    public function testFindByNameReturnsNullForUnknown(): void
    {
        $this->assertNull($this->lbl->findByName('nonexistent'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllListsAllLabelsAlphabetically(): void
    {
        $this->lbl->create('zzz');
        $this->lbl->create('aaa');
        $list = $this->lbl->all();
        $this->assertCount(2, $list);
        $this->assertSame('aaa', $list[0]['name']);
    }

    // ── attach / detach ───────────────────────────────────────────────────────

    public function testAttachIsIdempotent(): void
    {
        $id = $this->lbl->create('bug');
        $this->lbl->attach('issue', '1', $id);
        $this->lbl->attach('issue', '1', $id); // duplicate
        $labels = $this->lbl->forEntity('issue', '1');
        $this->assertCount(1, $labels);
    }

    public function testAttachThrowsOnEmptyEntityType(): void
    {
        $id = $this->lbl->create('bug');
        $this->expectException(\InvalidArgumentException::class);
        $this->lbl->attach('', '1', $id);
    }

    public function testDetachRemovesAssignment(): void
    {
        $id = $this->lbl->create('bug');
        $this->lbl->attach('issue', '1', $id);
        $this->assertTrue($this->lbl->detach('issue', '1', $id));
        $this->assertFalse($this->lbl->hasLabel('issue', '1', $id));
    }

    public function testDetachReturnsFalseForNonExistentAssignment(): void
    {
        $id = $this->lbl->create('bug');
        $this->assertFalse($this->lbl->detach('issue', '1', $id));
    }

    public function testDetachAllRemovesAllAssignments(): void
    {
        $id1 = $this->lbl->create('bug');
        $id2 = $this->lbl->create('feature');
        $this->lbl->attach('issue', '1', $id1);
        $this->lbl->attach('issue', '1', $id2);
        $count = $this->lbl->detachAll('issue', '1');
        $this->assertSame(2, $count);
        $this->assertSame([], $this->lbl->forEntity('issue', '1'));
    }

    // ── forEntity ─────────────────────────────────────────────────────────────

    public function testForEntityListsLabels(): void
    {
        $id1 = $this->lbl->create('bug');
        $id2 = $this->lbl->create('feature');
        $this->lbl->attach('issue', '42', $id1);
        $this->lbl->attach('issue', '42', $id2);
        $labels = $this->lbl->forEntity('issue', '42');
        $this->assertCount(2, $labels);
    }

    public function testForEntityReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->lbl->forEntity('issue', '99'));
    }

    // ── entitiesWithLabel ─────────────────────────────────────────────────────

    public function testEntitiesWithLabelListsEntities(): void
    {
        $id = $this->lbl->create('bug');
        $this->lbl->attach('issue', '1', $id);
        $this->lbl->attach('issue', '2', $id);
        $entities = $this->lbl->entitiesWithLabel($id);
        $this->assertCount(2, $entities);
    }

    public function testEntitiesWithLabelReturnsEmptyForUnused(): void
    {
        $id = $this->lbl->create('unused');
        $this->assertSame([], $this->lbl->entitiesWithLabel($id));
    }

    // ── hasLabel ──────────────────────────────────────────────────────────────

    public function testHasLabelReturnsTrueWhenAttached(): void
    {
        $id = $this->lbl->create('bug');
        $this->lbl->attach('issue', '1', $id);
        $this->assertTrue($this->lbl->hasLabel('issue', '1', $id));
    }

    public function testHasLabelReturnsFalseWhenNotAttached(): void
    {
        $id = $this->lbl->create('bug');
        $this->assertFalse($this->lbl->hasLabel('issue', '99', $id));
    }
}
