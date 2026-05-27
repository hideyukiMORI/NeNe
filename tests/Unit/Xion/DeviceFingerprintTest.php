<?php

declare(strict_types=1);

namespace Tests\Unit\Xion;

use Nene\Xion\DeviceFingerprint;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeviceFingerprintTest extends TestCase
{
    private PDO $pdo;
    private DeviceFingerprint $df;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE device_fingerprints (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      VARCHAR(255) NOT NULL,
                fingerprint  VARCHAR(64)  NOT NULL,
                user_agent   TEXT         NULL,
                ip_address   VARCHAR(45)  NULL,
                meta         TEXT         NULL,
                trust_level  INTEGER      NOT NULL DEFAULT 0,
                status       VARCHAR(20)  NOT NULL DEFAULT \'seen\',
                seen_count   INTEGER      NOT NULL DEFAULT 1,
                last_seen_at DATETIME     NOT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, fingerprint)
            )
        ');
        $this->df = new DeviceFingerprint($this->pdo);
    }

    // ── seen ──────────────────────────────────────────────────────────────────

    public function testSeenReturnsId(): void
    {
        $id = $this->df->seen('user-1', 'fp-abc');
        $this->assertGreaterThan(0, $id);
    }

    public function testSeenStoresHashedFingerprint(): void
    {
        $id  = $this->df->seen('user-1', 'fp-abc', '127.0.0.1');
        $row = $this->df->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(hash('sha256', 'fp-abc'), $row['fingerprint']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('127.0.0.1', $row['ip_address']);
    }

    public function testSeenStoresMeta(): void
    {
        $id  = $this->df->seen('user-1', 'fp-abc', null, ['screen' => '1920x1080']);
        $row = $this->df->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertStringContainsString('screen', $row['meta']);
    }

    public function testSeenIsIdempotent(): void
    {
        $id1 = $this->df->seen('user-1', 'fp-abc');
        $id2 = $this->df->seen('user-1', 'fp-abc');
        $this->assertSame($id1, $id2);
    }

    public function testSeenIncrementsSeenCount(): void
    {
        $id = $this->df->seen('user-1', 'fp-abc');
        $this->df->seen('user-1', 'fp-abc');
        $this->df->seen('user-1', 'fp-abc');
        $row = $this->df->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(3, (int)$row['seen_count']);
    }

    public function testSeenThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->df->seen('', 'fp-abc');
    }

    public function testSeenThrowsOnEmptyFingerprint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->df->seen('user-1', '');
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->df->get(9999));
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsByUserAndFingerprint(): void
    {
        $id  = $this->df->seen('user-1', 'fp-abc');
        $row = $this->df->find('user-1', 'fp-abc');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id, (int)$row['id']);
    }

    public function testFindReturnsNullWhenNotSeen(): void
    {
        $this->assertNull($this->df->find('user-1', 'fp-unknown'));
    }

    // ── isTrusted / isBlocked ─────────────────────────────────────────────────

    public function testIsTrustedReturnsFalseInitially(): void
    {
        $this->df->seen('user-1', 'fp-abc');
        $this->assertFalse($this->df->isTrusted('user-1', 'fp-abc'));
    }

    public function testIsTrustedReturnsTrueAfterTrust(): void
    {
        $id = $this->df->seen('user-1', 'fp-abc');
        $this->df->trust($id);
        $this->assertTrue($this->df->isTrusted('user-1', 'fp-abc'));
    }

    public function testIsBlockedReturnsFalseInitially(): void
    {
        $this->df->seen('user-1', 'fp-abc');
        $this->assertFalse($this->df->isBlocked('user-1', 'fp-abc'));
    }

    public function testIsBlockedReturnsTrueAfterBlock(): void
    {
        $id = $this->df->seen('user-1', 'fp-abc');
        $this->df->block($id);
        $this->assertTrue($this->df->isBlocked('user-1', 'fp-abc'));
    }

    // ── trust / block ─────────────────────────────────────────────────────────

    public function testTrustChangesStatus(): void
    {
        $id  = $this->df->seen('user-1', 'fp-abc');
        $this->assertTrue($this->df->trust($id));
        $row = $this->df->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(DeviceFingerprint::STATUS_TRUSTED, $row['status']);
    }

    public function testTrustReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->df->trust(9999));
    }

    public function testBlockChangesStatus(): void
    {
        $id  = $this->df->seen('user-1', 'fp-abc');
        $this->assertTrue($this->df->block($id));
        $row = $this->df->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(DeviceFingerprint::STATUS_BLOCKED, $row['status']);
    }

    public function testBlockReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->df->block(9999));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsAllDevices(): void
    {
        $this->df->seen('user-1', 'fp-abc');
        $this->df->seen('user-1', 'fp-def');
        $this->assertCount(2, $this->df->forUser('user-1'));
    }

    public function testForUserIsIsolatedByUser(): void
    {
        $this->df->seen('user-1', 'fp-abc');
        $this->df->seen('user-2', 'fp-abc');
        $this->assertCount(1, $this->df->forUser('user-1'));
        $this->assertCount(1, $this->df->forUser('user-2'));
    }

    public function testForUserReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->df->forUser('user-99'));
    }

    // ── trustedFor ────────────────────────────────────────────────────────────

    public function testTrustedForReturnsOnlyTrusted(): void
    {
        $id1 = $this->df->seen('user-1', 'fp-abc');
        $this->df->seen('user-1', 'fp-def');
        $this->df->trust($id1);
        $this->assertCount(1, $this->df->trustedFor('user-1'));
    }

    public function testTrustedForReturnsEmptyWhenNone(): void
    {
        $this->df->seen('user-1', 'fp-abc');
        $this->assertSame([], $this->df->trustedFor('user-1'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesRow(): void
    {
        $id = $this->df->seen('user-1', 'fp-abc');
        $this->assertTrue($this->df->remove($id));
        $this->assertNull($this->df->get($id));
    }

    public function testRemoveReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->df->remove(9999));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldSeenRows(): void
    {
        $this->pdo->exec(
            "INSERT INTO device_fingerprints (user_id, fingerprint, status, last_seen_at, created_at)
             VALUES ('user-1', 'hash1', 'seen', '2020-01-01 00:00:00', '2020-01-01 00:00:00')"
        );
        $deleted = $this->df->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(1, $deleted);
    }

    public function testPurgeOlderThanDoesNotDeleteTrusted(): void
    {
        $id = $this->df->seen('user-1', 'fp-abc');
        $this->df->trust($id);
        $this->pdo->exec("UPDATE device_fingerprints SET last_seen_at = '2020-01-01 00:00:00' WHERE id = {$id}");
        $deleted = $this->df->purgeOlderThan('2021-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
