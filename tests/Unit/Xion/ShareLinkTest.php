<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ShareLink;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ShareLink.
 */
final class ShareLinkTest extends TestCase
{
    private PDO $db;
    private ShareLink $sl;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE share_links (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                token         VARCHAR(64)  NOT NULL UNIQUE,
                target_type   VARCHAR(100) NOT NULL,
                target_id     VARCHAR(255) NOT NULL,
                owner_id      VARCHAR(255) NOT NULL DEFAULT \'\',
                label         VARCHAR(255) NOT NULL DEFAULT \'\',
                password_hash VARCHAR(64)  NULL,
                views         INTEGER      NOT NULL DEFAULT 0,
                max_views     INTEGER      NOT NULL DEFAULT 0,
                expires_at    DATETIME     NULL,
                created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->sl = new ShareLink($this->db);
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function testCreateReturns32HexToken(): void
    {
        $token = $this->sl->create('post', '42');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }

    public function testCreateTokensAreUnique(): void
    {
        $t1 = $this->sl->create('post', '42');
        $t2 = $this->sl->create('post', '42');
        $this->assertNotSame($t1, $t2);
    }

    public function testCreateThrowsOnEmptyTargetType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sl->create('', '42');
    }

    public function testCreateThrowsOnEmptyTargetId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sl->create('post', '');
    }

    // ── resolve ───────────────────────────────────────────────────────────────

    public function testResolveReturnsTargetInfo(): void
    {
        $token = $this->sl->create('post', '42', 'owner-1', 'My post');
        $link  = $this->sl->resolve($token);
        $this->assertNotNull($link);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('post', $link['target_type']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('42', $link['target_id']);
    }

    public function testResolveIncrementsViewCount(): void
    {
        $token = $this->sl->create('post', '42');
        $this->sl->resolve($token);
        $this->sl->resolve($token);
        $link = $this->sl->find($token);
        $this->assertNotNull($link);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(2, (int)$link['views']);
    }

    public function testResolveReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->sl->resolve('deadbeefdeadbeefdeadbeefdeadbeef'));
    }

    public function testResolveReturnsNullForExpiredLink(): void
    {
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO share_links (token, target_type, target_id, expires_at)
            VALUES ('expiredtoken12345678901234567890', 'post', '1', '{$past}')");
        $this->assertNull($this->sl->resolve('expiredtoken12345678901234567890'));
    }

    public function testResolveReturnsNullWhenViewsExhausted(): void
    {
        $token = $this->sl->create('post', '42', maxViews: 2);
        $this->sl->resolve($token);
        $this->sl->resolve($token);
        $this->assertNull($this->sl->resolve($token));
    }

    public function testResolveWithCorrectPassword(): void
    {
        $token = $this->sl->create('doc', '7', password: 'secret');
        $link  = $this->sl->resolve($token, 'secret');
        $this->assertNotNull($link);
    }

    public function testResolveWithWrongPassword(): void
    {
        $token = $this->sl->create('doc', '7', password: 'secret');
        $this->assertNull($this->sl->resolve($token, 'wrong'));
    }

    public function testResolveWithoutPasswordForProtectedLink(): void
    {
        $token = $this->sl->create('doc', '7', password: 'secret');
        $this->assertNull($this->sl->resolve($token, ''));
    }

    public function testResolveOmitsPasswordHash(): void
    {
        $token = $this->sl->create('doc', '7', password: 'secret');
        $link  = $this->sl->resolve($token, 'secret');
        $this->assertNotNull($link);
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
        $this->assertArrayNotHasKey('password_hash', $link);
    }

    // ── find ──────────────────────────────────────────────────────────────────

    public function testFindReturnsRowWithoutCheckingPassword(): void
    {
        $token = $this->sl->create('post', '42', 'owner-1');
        $link  = $this->sl->find($token);
        $this->assertNotNull($link);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($token, $link['token']);
    }

    public function testFindReturnsNullForUnknown(): void
    {
        $this->assertNull($this->sl->find('unknown'));
    }

    // ── revoke ────────────────────────────────────────────────────────────────

    public function testRevokeDeletesLink(): void
    {
        $token = $this->sl->create('post', '42');
        $this->assertTrue($this->sl->revoke($token));
        $this->assertNull($this->sl->resolve($token));
    }

    public function testRevokeReturnsFalseForUnknown(): void
    {
        $this->assertFalse($this->sl->revoke('nosuchtoken'));
    }

    // ── forOwner ──────────────────────────────────────────────────────────────

    public function testForOwnerListsLinks(): void
    {
        $this->sl->create('post', '1', 'owner-1');
        $this->sl->create('post', '2', 'owner-1');
        $this->sl->create('post', '3', 'owner-2');
        $links = $this->sl->forOwner('owner-1');
        $this->assertCount(2, $links);
    }

    public function testForOwnerReturnsEmptyForUnknown(): void
    {
        $this->assertSame([], $this->sl->forOwner('nobody'));
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function testPurgeExpiredDeletesOldLinks(): void
    {
        $past = (new \DateTimeImmutable())->modify('-1 second')->format('Y-m-d H:i:s');
        $this->db->exec("INSERT INTO share_links (token, target_type, target_id, expires_at)
            VALUES ('tok1111111111111111111111111111', 'post', '1', '{$past}')");
        $count = $this->sl->purgeExpired();
        $this->assertGreaterThan(0, $count);
    }

    public function testPurgeExpiredKeepsNonExpiredLinks(): void
    {
        $token = $this->sl->create('post', '42', ttlSeconds: 3600);
        $this->sl->purgeExpired();
        $this->assertNotNull($this->sl->find($token));
    }

    public function testPurgeExpiredKeepsLinksWithNoExpiry(): void
    {
        $token = $this->sl->create('post', '42'); // no TTL
        $this->sl->purgeExpired();
        $this->assertNotNull($this->sl->find($token));
    }
}
