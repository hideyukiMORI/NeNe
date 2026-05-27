<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\IncidentLog;
use PDO;
use PHPUnit\Framework\TestCase;

final class IncidentLogTest extends TestCase
{
    private PDO $pdo;
    private IncidentLog $il;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE incident_logs (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                title        TEXT         NOT NULL,
                severity     VARCHAR(10)  NOT NULL DEFAULT \'P3\',
                status       VARCHAR(20)  NOT NULL DEFAULT \'open\',
                description  TEXT         NULL,
                resolution   TEXT         NULL,
                opened_at    DATETIME     NOT NULL,
                resolved_at  DATETIME     NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->pdo->exec('
            CREATE TABLE incident_events (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                incident_id  INTEGER      NOT NULL,
                message      TEXT         NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->il = new IncidentLog($this->pdo);
    }

    // ── open ──────────────────────────────────────────────────────────────────

    public function testOpenReturnsId(): void
    {
        $id = $this->il->open('DB outage', IncidentLog::SEVERITY_P1);
        $this->assertGreaterThan(0, $id);
    }

    public function testOpenStoresFields(): void
    {
        $id  = $this->il->open('DB outage', IncidentLog::SEVERITY_P1, 'Primary DB unreachable.');
        $row = $this->il->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('DB outage', $row['title']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(IncidentLog::SEVERITY_P1, $row['severity']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(IncidentLog::STATUS_OPEN, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Primary DB unreachable.', $row['description']);
    }

    public function testOpenThrowsOnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->il->open('', IncidentLog::SEVERITY_P2);
    }

    public function testOpenThrowsOnInvalidSeverity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->il->open('Title', 'P5');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->il->get(9999));
    }

    // ── investigate ───────────────────────────────────────────────────────────

    public function testInvestigateChangesStatus(): void
    {
        $id  = $this->il->open('DB outage', IncidentLog::SEVERITY_P1);
        $this->assertTrue($this->il->investigate($id));
        $row = $this->il->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(IncidentLog::STATUS_INVESTIGATING, $row['status']);
    }

    public function testInvestigateReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->il->investigate(9999));
    }

    // ── resolve ───────────────────────────────────────────────────────────────

    public function testResolveChangesStatus(): void
    {
        $id  = $this->il->open('DB outage', IncidentLog::SEVERITY_P1);
        $this->assertTrue($this->il->resolve($id, 'Failover completed.'));
        $row = $this->il->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(IncidentLog::STATUS_RESOLVED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Failover completed.', $row['resolution']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($row['resolved_at']);
    }

    public function testResolveReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->il->resolve(9999));
    }

    // ── close ─────────────────────────────────────────────────────────────────

    public function testCloseChangesStatus(): void
    {
        $id  = $this->il->open('DB outage', IncidentLog::SEVERITY_P1);
        $this->il->resolve($id);
        $this->assertTrue($this->il->close($id));
        $row = $this->il->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(IncidentLog::STATUS_CLOSED, $row['status']);
    }

    public function testCloseReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->il->close(9999));
    }

    // ── updateSeverity ────────────────────────────────────────────────────────

    public function testUpdateSeverityChanges(): void
    {
        $id  = $this->il->open('DB outage', IncidentLog::SEVERITY_P3);
        $this->assertTrue($this->il->updateSeverity($id, IncidentLog::SEVERITY_P1));
        $row = $this->il->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(IncidentLog::SEVERITY_P1, $row['severity']);
    }

    public function testUpdateSeverityThrowsOnInvalid(): void
    {
        $id = $this->il->open('DB outage', IncidentLog::SEVERITY_P3);
        $this->expectException(\InvalidArgumentException::class);
        $this->il->updateSeverity($id, 'P0');
    }

    // ── addEvent / events ─────────────────────────────────────────────────────

    public function testAddEventReturnsId(): void
    {
        $id  = $this->il->open('DB outage', IncidentLog::SEVERITY_P1);
        $eid = $this->il->addEvent($id, 'On-call engineer paged.');
        $this->assertGreaterThan(0, $eid);
    }

    public function testAddEventThrowsOnEmptyMessage(): void
    {
        $id = $this->il->open('DB outage', IncidentLog::SEVERITY_P1);
        $this->expectException(\InvalidArgumentException::class);
        $this->il->addEvent($id, '');
    }

    public function testEventsReturnsAllOldestFirst(): void
    {
        $id = $this->il->open('DB outage', IncidentLog::SEVERITY_P1);
        $e1 = $this->il->addEvent($id, 'Event 1');
        $e2 = $this->il->addEvent($id, 'Event 2');
        $list = $this->il->events($id);
        $this->assertCount(2, $list);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($e1, (int)$list[0]['id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($e2, (int)$list[1]['id']);
    }

    public function testEventsReturnsEmptyWhenNone(): void
    {
        $id = $this->il->open('DB outage', IncidentLog::SEVERITY_P1);
        $this->assertSame([], $this->il->events($id));
    }

    // ── listOpen ──────────────────────────────────────────────────────────────

    public function testListOpenReturnsOpenAndInvestigating(): void
    {
        $id1 = $this->il->open('Inc 1', IncidentLog::SEVERITY_P2);
        $id2 = $this->il->open('Inc 2', IncidentLog::SEVERITY_P1);
        $this->il->investigate($id1);
        $list = $this->il->listOpen();
        $this->assertCount(2, $list);
        // P1 should come before P2 (ordered by severity ASC = alphabetical P1 < P2)
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id2, (int)$list[0]['id']);
        unset($id1);
    }

    public function testListOpenExcludesResolvedAndClosed(): void
    {
        $id = $this->il->open('Inc 1', IncidentLog::SEVERITY_P3);
        $this->il->resolve($id);
        $this->assertSame([], $this->il->listOpen());
    }

    // ── bySeverity ────────────────────────────────────────────────────────────

    public function testBySeverityFilters(): void
    {
        $this->il->open('P1 inc', IncidentLog::SEVERITY_P1);
        $this->il->open('P3 inc', IncidentLog::SEVERITY_P3);
        $this->assertCount(1, $this->il->bySeverity(IncidentLog::SEVERITY_P1));
        $this->assertCount(1, $this->il->bySeverity(IncidentLog::SEVERITY_P3));
    }

    public function testBySeverityReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->il->bySeverity(IncidentLog::SEVERITY_P2));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesClosedIncidentsAndEvents(): void
    {
        $this->pdo->exec(
            "INSERT INTO incident_logs (title, severity, status, opened_at, created_at)
             VALUES ('Old', 'P3', 'closed', '2020-01-01', '2020-01-01 00:00:00')"
        );
        $oldId = (int)$this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO incident_events (incident_id, message, created_at)
             VALUES ({$oldId}, 'Old event', '2020-01-01 00:00:00')"
        );
        $deleted = $this->il->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(1, $deleted);
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM incident_events WHERE incident_id = {$oldId}");
        $this->assertNotFalse($stmt);
        $this->assertSame(0, (int)$stmt->fetchColumn());
    }

    public function testPurgeOlderThanDoesNotDeleteOpen(): void
    {
        $id = $this->il->open('Open inc', IncidentLog::SEVERITY_P3);
        $this->pdo->exec("UPDATE incident_logs SET created_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->il->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
