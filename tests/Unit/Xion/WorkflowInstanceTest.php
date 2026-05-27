<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\WorkflowInstance;
use PDO;
use PHPUnit\Framework\TestCase;

final class WorkflowInstanceTest extends TestCase
{
    private PDO $pdo;
    private WorkflowInstance $wf;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE workflow_instances (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                type          VARCHAR(100) NOT NULL,
                entity_ref    VARCHAR(255) NOT NULL,
                current_state VARCHAR(100) NOT NULL,
                context       TEXT         NULL,
                status        VARCHAR(20)  NOT NULL DEFAULT \'active\',
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE workflow_transitions (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                instance_id     INTEGER      NOT NULL,
                from_state      VARCHAR(100) NOT NULL,
                to_state        VARCHAR(100) NOT NULL,
                actor_id        VARCHAR(255) NULL,
                metadata        TEXT         NULL,
                transitioned_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->wf = new WorkflowInstance($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresFields(): void
    {
        $id  = $this->wf->create('order', 'order-1', 'pending', ['items' => 3]);
        $row = $this->wf->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('order', $row['type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('order-1', $row['entity_ref']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('pending', $row['current_state']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(WorkflowInstance::STATUS_ACTIVE, $row['status']);
    }

    public function testCreateStoresContext(): void
    {
        $id  = $this->wf->create('order', 'order-1', 'pending', ['items' => 3]);
        $row = $this->wf->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $ctx = json_decode((string)$row['context'], true);
        $this->assertSame(3, $ctx['items']);
    }

    public function testCreateAllowsNullContext(): void
    {
        $id  = $this->wf->create('order', 'order-1', 'pending');
        $row = $this->wf->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['context']);
    }

    public function testCreateThrowsOnEmptyType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wf->create('', 'order-1', 'pending');
    }

    public function testCreateThrowsOnEmptyEntityRef(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wf->create('order', '', 'pending');
    }

    public function testCreateThrowsOnEmptyInitialState(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->wf->create('order', 'order-1', '');
    }

    // ── get / state ───────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->wf->get(9999));
    }

    public function testStateReturnsCurrentState(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->assertSame('pending', $this->wf->state($id));
    }

    public function testStateReturnsNullForMissingId(): void
    {
        $this->assertNull($this->wf->state(9999));
    }

    // ── transition ────────────────────────────────────────────────────────────

    public function testTransitionChangesCurrentState(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->assertTrue($this->wf->transition($id, 'processing', 'user-1'));
        $this->assertSame('processing', $this->wf->state($id));
    }

    public function testTransitionRecordsHistory(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->wf->transition($id, 'processing', 'user-1', ['note' => 'started']);
        $history = $this->wf->history($id);
        $this->assertCount(1, $history);
        $this->assertSame('pending', $history[0]['from_state']);
        $this->assertSame('processing', $history[0]['to_state']);
        $this->assertSame('user-1', $history[0]['actor_id']);
    }

    public function testTransitionRecordsMetadata(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->wf->transition($id, 'processing', 'user-1', ['note' => 'started']);
        $history = $this->wf->history($id);
        $meta    = json_decode((string)$history[0]['metadata'], true);
        $this->assertSame('started', $meta['note']);
    }

    public function testTransitionReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->wf->transition(9999, 'processing'));
    }

    public function testTransitionReturnsFalseForCompletedInstance(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->wf->complete($id, 'user-1');
        $this->assertFalse($this->wf->transition($id, 'processing'));
    }

    public function testTransitionThrowsOnEmptyNewState(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->expectException(\InvalidArgumentException::class);
        $this->wf->transition($id, '');
    }

    public function testMultipleTransitionsAreOrdered(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->wf->transition($id, 'processing');
        $this->wf->transition($id, 'shipped');
        $history = $this->wf->history($id);
        $this->assertCount(2, $history);
        $this->assertSame('pending', $history[0]['from_state']);
        $this->assertSame('processing', $history[1]['from_state']);
        $this->assertSame('shipped', $history[1]['to_state']);
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testCompleteChangesStatus(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->assertTrue($this->wf->complete($id, 'user-1'));
        $row = $this->wf->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(WorkflowInstance::STATUS_COMPLETED, $row['status']);
    }

    public function testCompleteRecordsTerminalTransition(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->wf->complete($id, 'user-1');
        $history = $this->wf->history($id);
        $this->assertCount(1, $history);
        $this->assertSame(WorkflowInstance::STATUS_COMPLETED, $history[0]['to_state']);
    }

    public function testCompleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->wf->complete(9999));
    }

    public function testCompleteReturnsFalseWhenAlreadyCompleted(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->wf->complete($id);
        $this->assertFalse($this->wf->complete($id));
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelChangesStatus(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->assertTrue($this->wf->cancel($id, 'user-1', 'customer request'));
        $row = $this->wf->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(WorkflowInstance::STATUS_CANCELLED, $row['status']);
    }

    public function testCancelStoresReason(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->wf->cancel($id, 'user-1', 'customer request');
        $history = $this->wf->history($id);
        $meta    = json_decode((string)$history[0]['metadata'], true);
        $this->assertSame('customer request', $meta['reason']);
    }

    public function testCancelReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->wf->cancel(9999));
    }

    // ── forEntity ─────────────────────────────────────────────────────────────

    public function testForEntityReturnsActiveInstances(): void
    {
        $this->wf->create('order', 'order-1', 'pending');
        $this->wf->create('order', 'order-1', 'pending');
        $list = $this->wf->forEntity('order', 'order-1');
        $this->assertCount(2, $list);
    }

    public function testForEntityExcludesCompleted(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->wf->complete($id);
        $this->assertCount(0, $this->wf->forEntity('order', 'order-1'));
    }

    public function testForEntityIsIsolatedByTypeAndRef(): void
    {
        $this->wf->create('order', 'order-1', 'pending');
        $this->wf->create('order', 'order-2', 'pending');
        $this->wf->create('shipment', 'order-1', 'pending');
        $this->assertCount(1, $this->wf->forEntity('order', 'order-1'));
        $this->assertCount(1, $this->wf->forEntity('shipment', 'order-1'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesInstanceAndHistory(): void
    {
        $id = $this->wf->create('order', 'order-1', 'pending');
        $this->wf->transition($id, 'processing');
        $this->assertTrue($this->wf->delete($id));
        $this->assertNull($this->wf->get($id));
        $this->assertSame([], $this->wf->history($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->wf->delete(9999));
    }
}
