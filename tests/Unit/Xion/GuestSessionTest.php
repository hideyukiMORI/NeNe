<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\GuestSession;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GuestSession.
 */
final class GuestSessionTest extends TestCase
{
    private PDO $db;
    private GuestSession $gs;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE guest_sessions (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                token      VARCHAR(64)  NOT NULL UNIQUE,
                user_id    VARCHAR(255) NULL,
                data       TEXT         NOT NULL DEFAULT \'{}\',
                expires_at DATETIME     NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->gs = new GuestSession($this->db);
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartReturns32HexToken(): void
    {
        $token = $this->gs->start();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    public function testStartTokensAreUnique(): void
    {
        $t1 = $this->gs->start();
        $t2 = $this->gs->start();
        $this->assertNotSame($t1, $t2);
    }

    // ── load ──────────────────────────────────────────────────────────────────

    public function testLoadReturnsSessionForActiveToken(): void
    {
        $token = $this->gs->start();
        $sess  = $this->gs->load($token);
        $this->assertNotNull($sess);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($token, $sess['token']);
    }

    public function testLoadReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->gs->load('deadbeefdeadbeefdeadbeefdeadbeef'));
    }

    public function testLoadReturnsNullForExpiredSession(): void
    {
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO guest_sessions (token, expires_at)
            VALUES ('expiredtokenabcdefghijklmnopqrst', '{$past}')");
        $this->assertNull($this->gs->load('expiredtokenabcdefghijklmnopqrst'));
    }

    // ── set / get ─────────────────────────────────────────────────────────────

    public function testSetAndGetValue(): void
    {
        $token = $this->gs->start();
        $this->gs->set($token, 'cart_id', 'cart-42');
        $this->assertSame('cart-42', $this->gs->get($token, 'cart_id'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $token = $this->gs->start();
        $this->assertNull($this->gs->get($token, 'missing'));
        $this->assertSame('fallback', $this->gs->get($token, 'missing', 'fallback'));
    }

    public function testGetReturnsDefaultForMissingSession(): void
    {
        $this->assertSame('none', $this->gs->get('nosuchtoken', 'key', 'none'));
    }

    public function testSetReturnsFalseForExpiredSession(): void
    {
        $this->assertFalse($this->gs->set('nosuchtoken', 'key', 'value'));
    }

    public function testSetMultipleKeys(): void
    {
        $token = $this->gs->start();
        $this->gs->set($token, 'a', 1);
        $this->gs->set($token, 'b', 'two');
        $this->assertSame(1, $this->gs->get($token, 'a'));
        $this->assertSame('two', $this->gs->get($token, 'b'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesKey(): void
    {
        $token = $this->gs->start();
        $this->gs->set($token, 'x', 'val');
        $this->gs->remove($token, 'x');
        $this->assertNull($this->gs->get($token, 'x'));
    }

    public function testRemoveReturnsFalseForExpiredSession(): void
    {
        $this->assertFalse($this->gs->remove('nosuchtoken', 'key'));
    }

    // ── linkUser ──────────────────────────────────────────────────────────────

    public function testLinkUserAssociatesUserId(): void
    {
        $token = $this->gs->start();
        $this->assertTrue($this->gs->linkUser($token, 'user-1'));
        $sess = $this->gs->load($token);
        $this->assertNotNull($sess);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $sess['user_id']);
    }

    public function testLinkUserReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->gs->linkUser('nosuchtoken', 'user-1'));
    }

    // ── touch ─────────────────────────────────────────────────────────────────

    public function testTouchExtendsExpiry(): void
    {
        $token = $this->gs->start(ttlSeconds: 60);
        $this->assertTrue($this->gs->touch($token, 3600));
        $sess = $this->gs->load($token);
        $this->assertNotNull($sess);
    }

    public function testTouchReturnsFalseForExpiredSession(): void
    {
        $this->assertFalse($this->gs->touch('nosuchtoken', 3600));
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function testDestroyDeletesSession(): void
    {
        $token = $this->gs->start();
        $this->assertTrue($this->gs->destroy($token));
        $this->assertNull($this->gs->load($token));
    }

    public function testDestroyReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->gs->destroy('nosuchtoken'));
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function testPurgeExpiredDeletesOldSessions(): void
    {
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO guest_sessions (token, expires_at)
            VALUES ('old1111111111111111111111111111', '{$past}')");
        $count = $this->gs->purgeExpired();
        $this->assertGreaterThan(0, $count);
    }

    public function testPurgeExpiredKeepsActiveSessions(): void
    {
        $token = $this->gs->start();
        $this->gs->purgeExpired();
        $this->assertNotNull($this->gs->load($token));
    }
}
