<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\DeviceToken;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeviceTokenTest extends TestCase
{
    private PDO $pdo;
    private DeviceToken $dt;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE device_tokens (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                token       TEXT         NOT NULL,
                platform    VARCHAR(20)  NOT NULL DEFAULT \'unknown\',
                is_active   TINYINT(1)   NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->dt = new DeviceToken($this->pdo);
    }

    // ── register ──────────────────────────────────────────────────────────────

    public function testRegisterReturnsId(): void
    {
        $id = $this->dt->register('user-1', 'tok-abc', DeviceToken::PLATFORM_ANDROID);
        $this->assertGreaterThan(0, $id);
    }

    public function testRegisterStoresCorrectly(): void
    {
        $id  = $this->dt->register('user-1', 'tok-abc', DeviceToken::PLATFORM_IOS);
        $row = $this->dt->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('tok-abc', $row['token']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(DeviceToken::PLATFORM_IOS, $row['platform']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['is_active']);
    }

    public function testRegisterReactivatesExistingToken(): void
    {
        $id1 = $this->dt->register('user-1', 'tok-abc', DeviceToken::PLATFORM_ANDROID);
        $this->dt->deactivate($id1);

        $id2 = $this->dt->register('user-1', 'tok-abc', DeviceToken::PLATFORM_ANDROID);
        $this->assertSame($id1, $id2);

        $row = $this->dt->find($id1);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(1, (int)$row['is_active']);
    }

    public function testRegisterSameTokenDifferentUsersCreatesNewRow(): void
    {
        $id1 = $this->dt->register('user-1', 'tok-abc');
        $id2 = $this->dt->register('user-2', 'tok-abc');
        $this->assertNotSame($id1, $id2);
    }

    public function testRegisterThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dt->register('', 'tok-abc');
    }

    public function testRegisterThrowsOnEmptyToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->dt->register('user-1', '');
    }

    // ── find / findByToken ────────────────────────────────────────────────────

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull($this->dt->find(9999));
    }

    public function testFindByTokenReturnsRow(): void
    {
        $this->dt->register('user-1', 'tok-xyz', DeviceToken::PLATFORM_WEB);
        $row = $this->dt->findByToken('tok-xyz');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
    }

    public function testFindByTokenReturnsNullWhenNotFound(): void
    {
        $this->assertNull($this->dt->findByToken('no-such-token'));
    }

    // ── deactivate ────────────────────────────────────────────────────────────

    public function testDeactivateSetsInactive(): void
    {
        $id     = $this->dt->register('user-1', 'tok-abc');
        $result = $this->dt->deactivate($id);
        $this->assertTrue($result);

        $row = $this->dt->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(0, (int)$row['is_active']);
    }

    public function testDeactivateReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->dt->deactivate(9999));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRow(): void
    {
        $id = $this->dt->register('user-1', 'tok-abc');
        $this->assertTrue($this->dt->delete($id));
        $this->assertNull($this->dt->find($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->dt->delete(9999));
    }

    // ── activeFor ─────────────────────────────────────────────────────────────

    public function testActiveForReturnsOnlyActiveTokens(): void
    {
        $id1 = $this->dt->register('user-1', 'tok-a');
        $id2 = $this->dt->register('user-1', 'tok-b');
        $this->dt->deactivate($id2);

        $active = $this->dt->activeFor('user-1');
        $this->assertCount(1, $active);
        $this->assertSame($id1, (int)$active[0]['id']);
    }

    public function testActiveForReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->dt->activeFor('nobody'));
    }

    public function testActiveForIsolatedByUser(): void
    {
        $this->dt->register('user-1', 'tok-a');
        $this->dt->register('user-2', 'tok-b');
        $this->assertCount(1, $this->dt->activeFor('user-1'));
        $this->assertCount(1, $this->dt->activeFor('user-2'));
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsAllTokens(): void
    {
        $id1 = $this->dt->register('user-1', 'tok-a');
        $id2 = $this->dt->register('user-1', 'tok-b');
        $this->dt->deactivate($id1);
        $this->assertCount(2, $this->dt->forUser('user-1'));
    }

    // ── deleteInactive ────────────────────────────────────────────────────────

    public function testDeleteInactiveRemovesInactiveTokens(): void
    {
        $id1 = $this->dt->register('user-1', 'tok-a');
        $id2 = $this->dt->register('user-1', 'tok-b');
        $this->dt->deactivate($id1);

        $count = $this->dt->deleteInactive('user-1');
        $this->assertSame(1, $count);
        $this->assertNull($this->dt->find($id1));
        $this->assertNotNull($this->dt->find($id2));
    }

    public function testDeleteInactiveReturnsZeroWhenNone(): void
    {
        $this->dt->register('user-1', 'tok-a');
        $this->assertSame(0, $this->dt->deleteInactive('user-1'));
    }

    // ── countActive ───────────────────────────────────────────────────────────

    public function testCountActive(): void
    {
        $this->dt->register('user-1', 'tok-a');
        $id2 = $this->dt->register('user-1', 'tok-b');
        $this->dt->deactivate($id2);
        $this->assertSame(1, $this->dt->countActive('user-1'));
    }

    public function testCountActiveReturnsZeroWhenNone(): void
    {
        $this->assertSame(0, $this->dt->countActive('nobody'));
    }
}
