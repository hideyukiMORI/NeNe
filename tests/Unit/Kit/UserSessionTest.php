<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\UserSession;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserSessionTest extends TestCase
{
    private PDO $pdo;
    private UserSession $us;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE user_sessions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                token_hash  VARCHAR(64)  NOT NULL UNIQUE,
                device_info TEXT         NULL,
                ip_address  VARCHAR(45)  NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT \'active\',
                last_active DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at  DATETIME     NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->us = new UserSession($this->pdo);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturnsIdAndToken(): void
    {
        $result = $this->us->create('user-1');
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertNotEmpty($result['token']);
    }

    public function testCreateStoresHashedToken(): void
    {
        $result = $this->us->create('user-1');
        $row    = $this->us->get($result['id']);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotSame($result['token'], $row['token_hash']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(hash('sha256', $result['token']), $row['token_hash']);
    }

    public function testCreateStoresDeviceInfo(): void
    {
        $result = $this->us->create('user-1', 'Mozilla/5.0', '1.2.3.4');
        $row    = $this->us->get($result['id']);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Mozilla/5.0', $row['device_info']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1.2.3.4', $row['ip_address']);
    }

    public function testCreateThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->us->create('');
    }

    public function testCreateThrowsOnInvalidTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->us->create('user-1', null, null, 0);
    }

    // ── findByToken ───────────────────────────────────────────────────────────

    public function testFindByTokenReturnsRow(): void
    {
        $result  = $this->us->create('user-1', null, null, 3600);
        $session = $this->us->findByToken($result['token']);
        $this->assertNotNull($session);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $session['user_id']);
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->us->findByToken('invalid-token'));
    }

    public function testFindByTokenReturnsNullForInvalidatedSession(): void
    {
        $result = $this->us->create('user-1', null, null, 3600);
        $this->us->invalidate($result['id']);
        $this->assertNull($this->us->findByToken($result['token']));
    }

    public function testFindByTokenReturnsNullForExpiredSession(): void
    {
        $result = $this->us->create('user-1', null, null, 1);
        // Manually expire the session
        $this->pdo->exec("UPDATE user_sessions SET expires_at = '2000-01-01 00:00:00' WHERE id = " . $result['id']);
        $this->assertNull($this->us->findByToken($result['token']));
    }

    public function testFindByTokenMarksExpiredSessions(): void
    {
        $result = $this->us->create('user-1', null, null, 3600);
        $this->pdo->exec("UPDATE user_sessions SET expires_at = '2000-01-01 00:00:00' WHERE id = " . $result['id']);
        $this->us->findByToken($result['token']);
        $row = $this->us->get($result['id']);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(UserSession::STATUS_EXPIRED, $row['status']);
    }

    // ── touch ─────────────────────────────────────────────────────────────────

    public function testTouchReturnsTrueForActiveSession(): void
    {
        $result = $this->us->create('user-1', null, null, 3600);
        $this->assertTrue($this->us->touch($result['id']));
    }

    public function testTouchReturnsFalseForInvalidatedSession(): void
    {
        $result = $this->us->create('user-1', null, null, 3600);
        $this->us->invalidate($result['id']);
        $this->assertFalse($this->us->touch($result['id']));
    }

    // ── invalidate ────────────────────────────────────────────────────────────

    public function testInvalidateChangesStatus(): void
    {
        $result = $this->us->create('user-1', null, null, 3600);
        $this->assertTrue($this->us->invalidate($result['id']));
        $row = $this->us->get($result['id']);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(UserSession::STATUS_INVALIDATED, $row['status']);
    }

    public function testInvalidateReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->us->invalidate(9999));
    }

    // ── invalidateAll ─────────────────────────────────────────────────────────

    public function testInvalidateAllReturnsCount(): void
    {
        $this->us->create('user-1', null, null, 3600);
        $this->us->create('user-1', null, null, 3600);
        $this->us->create('user-2', null, null, 3600);
        $this->assertSame(2, $this->us->invalidateAll('user-1'));
    }

    public function testInvalidateAllOnlyAffectsActiveSessions(): void
    {
        $r1 = $this->us->create('user-1', null, null, 3600);
        $r2 = $this->us->create('user-1', null, null, 3600);
        $this->us->invalidate($r1['id']);
        $this->assertSame(1, $this->us->invalidateAll('user-1'));
        $row = $this->us->get($r2['id']);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(UserSession::STATUS_INVALIDATED, $row['status']);
    }

    // ── activeSessions ────────────────────────────────────────────────────────

    public function testActiveSessionsReturnsOnlyActive(): void
    {
        $r1 = $this->us->create('user-1', null, null, 3600);
        $this->us->create('user-1', null, null, 3600);
        $this->us->invalidate($r1['id']);
        $active = $this->us->activeSessions('user-1');
        $this->assertCount(1, $active);
    }

    public function testActiveSessionsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->us->activeSessions('user-1'));
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function testPurgeExpiredDeletesOldRows(): void
    {
        $result = $this->us->create('user-1', null, null, 3600);
        $this->us->invalidate($result['id']);
        // Set created_at in the past
        $this->pdo->exec("UPDATE user_sessions SET created_at = '2020-01-01 00:00:00' WHERE id = " . $result['id']);
        $deleted = $this->us->purgeExpired('2025-01-01 00:00:00');
        $this->assertSame(1, $deleted);
    }

    public function testPurgeExpiredLeavesActiveSessions(): void
    {
        $result = $this->us->create('user-1', null, null, 3600);
        $this->pdo->exec("UPDATE user_sessions SET created_at = '2020-01-01 00:00:00' WHERE id = " . $result['id']);
        $deleted = $this->us->purgeExpired('2025-01-01 00:00:00');
        $this->assertSame(0, $deleted);
    }
}
