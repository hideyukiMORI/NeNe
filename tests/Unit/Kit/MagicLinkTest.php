<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\MagicLink;
use PDO;
use PHPUnit\Framework\TestCase;

final class MagicLinkTest extends TestCase
{
    private PDO $db;
    private MagicLink $ml;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE magic_links (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                email      VARCHAR(255) NOT NULL,
                token_hash VARCHAR(64)  NOT NULL UNIQUE,
                used_at    DATETIME     DEFAULT NULL,
                expires_at DATETIME     NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ml = new MagicLink($this->db);
    }

    public function testGenerateReturnsRawToken(): void
    {
        $token = $this->ml->generate('user@example.com');
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token)); // 32 bytes → 64 hex chars
    }

    public function testGenerateThrowsOnEmptyEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ml->generate('');
    }

    public function testIsValidReturnsTrueForFreshToken(): void
    {
        $token = $this->ml->generate('user@example.com');
        $this->assertTrue($this->ml->isValid($token));
    }

    public function testIsValidReturnsFalseForUnknownToken(): void
    {
        $this->assertFalse($this->ml->isValid('deadbeef'));
    }

    public function testIsValidReturnsFalseForExpiredToken(): void
    {
        $this->db->exec(
            "INSERT INTO magic_links (email, token_hash, expires_at)
             VALUES ('a@b.com', '" . hash('sha256', 'test-token') . "', '2000-01-01 00:00:00')"
        );
        $this->assertFalse($this->ml->isValid('test-token'));
    }

    public function testConsumeReturnsRecordAndMarksUsed(): void
    {
        $token  = $this->ml->generate('user@example.com');
        $record = $this->ml->consume($token);
        $this->assertNotNull($record);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user@example.com', $record['email']);
    }

    public function testConsumeReturnsFalseOnSecondUse(): void
    {
        $token = $this->ml->generate('user@example.com');
        $this->ml->consume($token);
        $this->assertNull($this->ml->consume($token));
    }

    public function testConsumeReturnsFalseForInvalidToken(): void
    {
        $this->assertNull($this->ml->consume('bad-token'));
    }

    public function testConsumeReturnsFalseForExpiredToken(): void
    {
        $raw  = 'expired-raw-token';
        $hash = hash('sha256', $raw);
        $this->db->exec(
            "INSERT INTO magic_links (email, token_hash, expires_at)
             VALUES ('a@b.com', '{$hash}', '2000-01-01 00:00:00')"
        );
        $this->assertNull($this->ml->consume($raw));
    }

    public function testConsumedTokenIsNoLongerValid(): void
    {
        $token = $this->ml->generate('user@example.com');
        $this->ml->consume($token);
        $this->assertFalse($this->ml->isValid($token));
    }

    public function testPendingCount(): void
    {
        $this->assertSame(0, $this->ml->pendingCount('user@example.com'));
        $this->ml->generate('user@example.com');
        $this->ml->generate('user@example.com');
        $this->assertSame(2, $this->ml->pendingCount('user@example.com'));
    }

    public function testPendingCountExcludesUsedTokens(): void
    {
        $token = $this->ml->generate('user@example.com');
        $this->ml->consume($token);
        $this->assertSame(0, $this->ml->pendingCount('user@example.com'));
    }

    public function testPurgeExpired(): void
    {
        $this->ml->generate('user@example.com');
        $this->db->exec(
            "INSERT INTO magic_links (email, token_hash, expires_at)
             VALUES ('a@b.com', 'aaa', '2000-01-01 00:00:00')"
        );
        $deleted = $this->ml->purgeExpired();
        $this->assertSame(1, $deleted);
    }

    public function testPurgeExpiredAlsoRemovesUsedTokens(): void
    {
        $token = $this->ml->generate('user@example.com');
        $this->ml->consume($token);
        $deleted = $this->ml->purgeExpired();
        $this->assertSame(1, $deleted);
    }
}
