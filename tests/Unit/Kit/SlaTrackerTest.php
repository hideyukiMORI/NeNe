<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\SlaTracker;
use PDO;
use PHPUnit\Framework\TestCase;

final class SlaTrackerTest extends TestCase
{
    private PDO $pdo;
    private SlaTracker $sl;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE sla_trackers (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type     VARCHAR(100) NOT NULL,
                entity_id       VARCHAR(255) NOT NULL,
                sla_type        VARCHAR(100) NOT NULL,
                started_at      DATETIME     NOT NULL,
                deadline_at     DATETIME     NOT NULL,
                resolved_at     DATETIME     NULL,
                paused_at       DATETIME     NULL,
                paused_seconds  INTEGER      NOT NULL DEFAULT 0,
                status          VARCHAR(20)  NOT NULL DEFAULT \'active\',
                created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->sl = new SlaTracker($this->pdo);
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartReturnsId(): void
    {
        $id = $this->sl->start('ticket', 'tkt-1', 'first_response', '2026-06-01 10:00:00', 3600);
        $this->assertGreaterThan(0, $id);
    }

    public function testStartStoresFields(): void
    {
        $id  = $this->sl->start('ticket', 'tkt-1', 'first_response', '2026-06-01 10:00:00', 3600);
        $row = $this->sl->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('ticket', $row['entity_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('tkt-1', $row['entity_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('first_response', $row['sla_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SlaTracker::STATUS_ACTIVE, $row['status']);
    }

    public function testStartCalculatesDeadline(): void
    {
        $id  = $this->sl->start('ticket', 'tkt-1', 'first_response', '2026-06-01 10:00:00', 3600);
        $row = $this->sl->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-06-01 11:00:00', $row['deadline_at']);
    }

    public function testStartThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sl->start('', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
    }

    public function testStartThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sl->start('ticket', '', 'fr', '2026-06-01 10:00:00', 3600);
    }

    public function testStartThrowsOnEmptySlaType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sl->start('ticket', 'tkt-1', '', '2026-06-01 10:00:00', 3600);
    }

    public function testStartThrowsOnZeroDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 0);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->sl->get(9999));
    }

    // ── resolve ───────────────────────────────────────────────────────────────

    public function testResolveChangesStatus(): void
    {
        $id  = $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        $this->assertTrue($this->sl->resolve($id, '2026-06-01 10:30:00'));
        $row = $this->sl->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SlaTracker::STATUS_RESOLVED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-06-01 10:30:00', $row['resolved_at']);
    }

    public function testResolveReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->sl->resolve(9999));
    }

    // ── pause / resume ────────────────────────────────────────────────────────

    public function testPauseChangesStatus(): void
    {
        $id  = $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        $this->assertTrue($this->sl->pause($id, '2026-06-01 10:15:00'));
        $row = $this->sl->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SlaTracker::STATUS_PAUSED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('2026-06-01 10:15:00', $row['paused_at']);
    }

    public function testPauseIsNopIfAlreadyPaused(): void
    {
        $id = $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        $this->sl->pause($id, '2026-06-01 10:15:00');
        $this->assertFalse($this->sl->pause($id, '2026-06-01 10:20:00'));
    }

    public function testResumeAccumulatesPausedSeconds(): void
    {
        $id = $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        $this->sl->pause($id, '2026-06-01 10:15:00');
        $this->sl->resume($id, '2026-06-01 10:45:00'); // 30 min = 1800 s paused
        $row = $this->sl->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SlaTracker::STATUS_ACTIVE, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1800, (int)$row['paused_seconds']);
    }

    public function testResumeReturnsFalseIfNotPaused(): void
    {
        $id = $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        $this->assertFalse($this->sl->resume($id));
    }

    public function testResumeReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->sl->resume(9999));
    }

    // ── markBreached ──────────────────────────────────────────────────────────

    public function testMarkBreachedChangesStatus(): void
    {
        $id  = $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        $this->assertTrue($this->sl->markBreached($id));
        $row = $this->sl->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(SlaTracker::STATUS_BREACHED, $row['status']);
    }

    public function testMarkBreachedReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->sl->markBreached(9999));
    }

    // ── breached ──────────────────────────────────────────────────────────────

    public function testBreachedReturnsActiveOverdueTrackers(): void
    {
        $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        // Not overdue yet at 10:30
        $list = $this->sl->breached('2026-06-01 10:30:00');
        $this->assertSame([], $list);
        // Overdue at 11:01
        $list = $this->sl->breached('2026-06-01 11:01:00');
        $this->assertCount(1, $list);
    }

    public function testBreachedExcludesResolved(): void
    {
        $id = $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        $this->sl->resolve($id, '2026-06-01 11:01:00');
        $this->assertSame([], $this->sl->breached('2026-06-01 11:01:00'));
    }

    // ── forEntity ─────────────────────────────────────────────────────────────

    public function testForEntityReturnsAllTrackers(): void
    {
        $this->sl->start('ticket', 'tkt-1', 'first_response', '2026-06-01 10:00:00', 3600);
        $this->sl->start('ticket', 'tkt-1', 'resolution', '2026-06-01 10:00:00', 86400);
        $this->assertCount(2, $this->sl->forEntity('ticket', 'tkt-1'));
    }

    public function testForEntityIsIsolatedByEntity(): void
    {
        $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        $this->sl->start('ticket', 'tkt-2', 'fr', '2026-06-01 10:00:00', 3600);
        $this->assertCount(1, $this->sl->forEntity('ticket', 'tkt-1'));
    }

    public function testForEntityReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->sl->forEntity('ticket', 'tkt-99'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldTerminalRows(): void
    {
        $this->pdo->exec(
            "INSERT INTO sla_trackers
                 (entity_type, entity_id, sla_type, started_at, deadline_at, status, created_at)
             VALUES ('ticket', 'tkt-1', 'fr', '2020-01-01', '2020-01-01 01:00:00', 'resolved', '2020-01-01')"
        );
        $this->pdo->exec(
            "INSERT INTO sla_trackers
                 (entity_type, entity_id, sla_type, started_at, deadline_at, status, created_at)
             VALUES ('ticket', 'tkt-2', 'fr', '2020-01-01', '2020-01-01 01:00:00', 'breached', '2020-01-01')"
        );
        $deleted = $this->sl->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(2, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeleteActive(): void
    {
        $id = $this->sl->start('ticket', 'tkt-1', 'fr', '2026-06-01 10:00:00', 3600);
        $this->pdo->exec("UPDATE sla_trackers SET created_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->sl->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
