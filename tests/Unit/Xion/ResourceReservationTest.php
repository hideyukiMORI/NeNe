<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\ResourceReservation;
use PDO;
use PHPUnit\Framework\TestCase;

final class ResourceReservationTest extends TestCase
{
    private PDO $pdo;
    private ResourceReservation $rr;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE resource_reservations (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                resource_id VARCHAR(255) NOT NULL,
                user_id     VARCHAR(255) NOT NULL,
                starts_at   DATETIME     NOT NULL,
                ends_at     DATETIME     NOT NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT \'confirmed\',
                notes       TEXT         NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->rr = new ResourceReservation($this->pdo);
    }

    // ── reserve ───────────────────────────────────────────────────────────────

    public function testReserveReturnsId(): void
    {
        $id = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $this->assertGreaterThan(0, $id);
    }

    public function testReserveStoresFields(): void
    {
        $id  = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00', 'Team sync');
        $row = $this->rr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('room-A', $row['resource_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ResourceReservation::STATUS_CONFIRMED, $row['status']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Team sync', $row['notes']);
    }

    public function testReserveThrowsOnEmptyResourceId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rr->reserve('', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
    }

    public function testReserveThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rr->reserve('room-A', '', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
    }

    public function testReserveThrowsWhenStartsAtEqualsEndsAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 10:00:00');
    }

    public function testReserveThrowsWhenStartsAtAfterEndsAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rr->reserve('room-A', 'user-1', '2026-06-01 11:00:00', '2026-06-01 10:00:00');
    }

    public function testReserveThrowsOnOverlap(): void
    {
        $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 12:00:00');
        $this->expectException(\RuntimeException::class);
        $this->rr->reserve('room-A', 'user-2', '2026-06-01 11:00:00', '2026-06-01 13:00:00');
    }

    public function testReserveAllowsAdjacentSlots(): void
    {
        $id1 = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $id2 = $this->rr->reserve('room-A', 'user-2', '2026-06-01 11:00:00', '2026-06-01 12:00:00');
        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan(0, $id2);
    }

    public function testReserveAllowsSameSlotDifferentResource(): void
    {
        $id1 = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $id2 = $this->rr->reserve('room-B', 'user-2', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan(0, $id2);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->rr->get(9999));
    }

    // ── isAvailable ───────────────────────────────────────────────────────────

    public function testIsAvailableReturnsTrueWhenFree(): void
    {
        $this->assertTrue($this->rr->isAvailable('room-A', '2026-06-01 10:00:00', '2026-06-01 11:00:00'));
    }

    public function testIsAvailableReturnsFalseWhenConflict(): void
    {
        $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 12:00:00');
        $this->assertFalse($this->rr->isAvailable('room-A', '2026-06-01 11:00:00', '2026-06-01 13:00:00'));
    }

    public function testIsAvailableTrueAfterCancel(): void
    {
        $id = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $this->rr->cancel($id);
        $this->assertTrue($this->rr->isAvailable('room-A', '2026-06-01 10:00:00', '2026-06-01 11:00:00'));
    }

    public function testIsAvailableWithExcludeId(): void
    {
        $id = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        // Same slot is available if we exclude the existing reservation
        $this->assertTrue($this->rr->isAvailable('room-A', '2026-06-01 10:00:00', '2026-06-01 11:00:00', $id));
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function testCancelChangesStatus(): void
    {
        $id  = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $this->assertTrue($this->rr->cancel($id));
        $row = $this->rr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ResourceReservation::STATUS_CANCELLED, $row['status']);
    }

    public function testCancelReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->rr->cancel(9999));
    }

    // ── complete ──────────────────────────────────────────────────────────────

    public function testCompleteChangesStatus(): void
    {
        $id  = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $this->assertTrue($this->rr->complete($id));
        $row = $this->rr->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(ResourceReservation::STATUS_COMPLETED, $row['status']);
    }

    public function testCompleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->rr->complete(9999));
    }

    // ── forResource ───────────────────────────────────────────────────────────

    public function testForResourceReturnsOverlappingReservations(): void
    {
        $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        // Non-overlapping
        $this->rr->reserve('room-A', 'user-2', '2026-06-01 14:00:00', '2026-06-01 15:00:00');
        $list = $this->rr->forResource('room-A', '2026-06-01 09:00:00', '2026-06-01 12:00:00');
        $this->assertCount(1, $list);
    }

    public function testForResourceExcludesCancelled(): void
    {
        $id = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $this->rr->cancel($id);
        $list = $this->rr->forResource('room-A', '2026-06-01 09:00:00', '2026-06-01 12:00:00');
        $this->assertSame([], $list);
    }

    public function testForResourceIsIsolatedByResource(): void
    {
        $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $this->rr->reserve('room-B', 'user-2', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $this->assertCount(1, $this->rr->forResource('room-A', '2026-06-01 09:00:00', '2026-06-01 12:00:00'));
        $this->assertCount(1, $this->rr->forResource('room-B', '2026-06-01 09:00:00', '2026-06-01 12:00:00'));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsAllStatuses(): void
    {
        $id1 = $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $id2 = $this->rr->reserve('room-B', 'user-1', '2026-06-02 10:00:00', '2026-06-02 11:00:00');
        $this->rr->cancel($id1);
        $list = $this->rr->forUser('user-1');
        $this->assertCount(2, $list);
        unset($id2);
    }

    public function testForUserIsIsolatedByUser(): void
    {
        $this->rr->reserve('room-A', 'user-1', '2026-06-01 10:00:00', '2026-06-01 11:00:00');
        $this->rr->reserve('room-A', 'user-2', '2026-06-02 10:00:00', '2026-06-02 11:00:00');
        $this->assertCount(1, $this->rr->forUser('user-1'));
        $this->assertCount(1, $this->rr->forUser('user-2'));
    }

    public function testForUserReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->rr->forUser('user-99'));
    }

    // ── upcoming ──────────────────────────────────────────────────────────────

    public function testUpcomingReturnsConfirmedFutureReservations(): void
    {
        $this->rr->reserve('room-A', 'user-1', '2099-06-01 10:00:00', '2099-06-01 11:00:00');
        $this->assertCount(1, $this->rr->upcoming('room-A'));
    }

    public function testUpcomingExcludesPastReservations(): void
    {
        $this->pdo->exec(
            "INSERT INTO resource_reservations
                 (resource_id, user_id, starts_at, ends_at, status)
             VALUES ('room-A', 'user-1', '2020-01-01 10:00:00', '2020-01-01 11:00:00', 'confirmed')"
        );
        $this->assertSame([], $this->rr->upcoming('room-A'));
    }

    public function testUpcomingExcludesCancelled(): void
    {
        $id = $this->rr->reserve('room-A', 'user-1', '2099-06-01 10:00:00', '2099-06-01 11:00:00');
        $this->rr->cancel($id);
        $this->assertSame([], $this->rr->upcoming('room-A'));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldNonConfirmed(): void
    {
        $this->pdo->exec(
            "INSERT INTO resource_reservations
                 (resource_id, user_id, starts_at, ends_at, status, created_at)
             VALUES ('room-A', 'user-1', '2020-01-01 10:00:00', '2020-01-01 11:00:00', 'cancelled', '2020-01-01 00:00:00')"
        );
        $this->pdo->exec(
            "INSERT INTO resource_reservations
                 (resource_id, user_id, starts_at, ends_at, status, created_at)
             VALUES ('room-B', 'user-2', '2020-01-02 10:00:00', '2020-01-02 11:00:00', 'completed', '2020-01-02 00:00:00')"
        );
        $deleted = $this->rr->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(2, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeleteConfirmed(): void
    {
        $id = $this->rr->reserve('room-A', 'user-1', '2099-06-01 10:00:00', '2099-06-01 11:00:00');
        $this->pdo->exec("UPDATE resource_reservations SET created_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->rr->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeleteRecent(): void
    {
        $this->pdo->exec(
            "INSERT INTO resource_reservations
                 (resource_id, user_id, starts_at, ends_at, status, created_at)
             VALUES ('room-A', 'user-1', '2025-01-01 10:00:00', '2025-01-01 11:00:00', 'cancelled', '2025-01-01 00:00:00')"
        );
        $deleted = $this->rr->purgeOlderThan('2020-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
