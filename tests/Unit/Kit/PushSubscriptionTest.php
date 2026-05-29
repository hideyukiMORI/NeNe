<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\PushSubscription;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PushSubscription.
 */
final class PushSubscriptionTest extends TestCase
{
    private PDO $db;
    private PushSubscription $ps;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE push_subscriptions (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       VARCHAR(255) NOT NULL,
                endpoint      TEXT         NOT NULL UNIQUE,
                p256dh        TEXT         NOT NULL DEFAULT \'\',
                auth          TEXT         NOT NULL DEFAULT \'\',
                device_hint   VARCHAR(100) NOT NULL DEFAULT \'\',
                subscribed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ps = new PushSubscription($this->db);
    }

    // ── save ──────────────────────────────────────────────────────────────────

    public function testSaveStoresSubscription(): void
    {
        $this->ps->save('user-1', 'https://push.example.com/ep1', 'key123', 'auth456', 'Chrome');
        $row = $this->ps->findByEndpoint('https://push.example.com/ep1');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('key123', $row['p256dh']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Chrome', $row['device_hint']);
    }

    public function testSaveIsIdempotentForSameEndpoint(): void
    {
        $ep = 'https://push.example.com/ep1';
        $this->ps->save('user-1', $ep, 'key1', 'auth1');
        $this->ps->save('user-1', $ep, 'key2', 'auth2'); // update keys
        $this->assertSame(1, $this->ps->countForUser('user-1'));
        $row = $this->ps->findByEndpoint($ep);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('key2', $row['p256dh']);
    }

    public function testSaveThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ps->save('', 'https://push.example.com/ep1');
    }

    public function testSaveThrowsOnEmptyEndpoint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ps->save('user-1', '');
    }

    // ── forUser ───────────────────────────────────────────────────────────────

    public function testForUserReturnsAllSubscriptions(): void
    {
        $this->ps->save('user-1', 'https://push.example.com/a');
        $this->ps->save('user-1', 'https://push.example.com/b');
        $this->ps->save('user-2', 'https://push.example.com/c');
        $rows = $this->ps->forUser('user-1');
        $this->assertCount(2, $rows);
    }

    public function testForUserReturnsEmptyForUnknownUser(): void
    {
        $this->assertSame([], $this->ps->forUser('user-1'));
    }

    // ── findByEndpoint ────────────────────────────────────────────────────────

    public function testFindByEndpointReturnsNullForUnknown(): void
    {
        $this->assertNull($this->ps->findByEndpoint('https://push.example.com/unknown'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesSubscription(): void
    {
        $ep = 'https://push.example.com/ep1';
        $this->ps->save('user-1', $ep);
        $this->assertTrue($this->ps->remove($ep));
        $this->assertNull($this->ps->findByEndpoint($ep));
    }

    public function testRemoveReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->ps->remove('https://push.example.com/unknown'));
    }

    // ── removeAllForUser ──────────────────────────────────────────────────────

    public function testRemoveAllForUserDeletesAll(): void
    {
        $this->ps->save('user-1', 'https://push.example.com/a');
        $this->ps->save('user-1', 'https://push.example.com/b');
        $this->ps->save('user-2', 'https://push.example.com/c');
        $count = $this->ps->removeAllForUser('user-1');
        $this->assertSame(2, $count);
        $this->assertSame(0, $this->ps->countForUser('user-1'));
        $this->assertSame(1, $this->ps->countForUser('user-2'));
    }

    // ── countForUser ──────────────────────────────────────────────────────────

    public function testCountForUserReturnsCorrectCount(): void
    {
        $this->assertSame(0, $this->ps->countForUser('user-1'));
        $this->ps->save('user-1', 'https://push.example.com/a');
        $this->ps->save('user-1', 'https://push.example.com/b');
        $this->assertSame(2, $this->ps->countForUser('user-1'));
    }

    // ── reassign endpoint to different user ───────────────────────────────────

    public function testSaveCanReassignEndpointToNewUser(): void
    {
        $ep = 'https://push.example.com/ep1';
        $this->ps->save('user-1', $ep);
        $this->ps->save('user-2', $ep); // browser re-subscribed under user-2
        $row = $this->ps->findByEndpoint($ep);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-2', $row['user_id']);
    }
}
