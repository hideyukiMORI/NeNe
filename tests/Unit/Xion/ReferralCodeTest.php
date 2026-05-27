<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ReferralCode;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReferralCode.
 */
final class ReferralCodeTest extends TestCase
{
    private PDO $db;
    private ReferralCode $ref;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE referral_codes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    VARCHAR(255) NOT NULL UNIQUE,
                code       VARCHAR(20)  NOT NULL UNIQUE,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE referral_conversions (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                code          VARCHAR(20)  NOT NULL,
                referred_user VARCHAR(255) NOT NULL UNIQUE,
                converted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
        ');
        $this->ref = new ReferralCode($this->db);
    }

    // ── getOrCreate ───────────────────────────────────────────────────────────

    public function testGetOrCreateReturnsString(): void
    {
        $code = $this->ref->getOrCreate('user-1');
        $this->assertIsString($code);
        $this->assertSame(8, strlen($code));
    }

    public function testGetOrCreateIsIdempotent(): void
    {
        $code1 = $this->ref->getOrCreate('user-1');
        $code2 = $this->ref->getOrCreate('user-1');
        $this->assertSame($code1, $code2);
    }

    public function testGetOrCreateDifferentUsersGetDifferentCodes(): void
    {
        $code1 = $this->ref->getOrCreate('user-1');
        $code2 = $this->ref->getOrCreate('user-2');
        $this->assertNotSame($code1, $code2);
    }

    public function testGetOrCreateCodeUsesUnambiguousAlphabet(): void
    {
        $code = $this->ref->getOrCreate('user-1');
        // No 0, 1, O, I, L
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]+$/', $code);
    }

    // ── findByCode ────────────────────────────────────────────────────────────

    public function testFindByCodeReturnsRow(): void
    {
        $code = $this->ref->getOrCreate('user-1');
        $row  = $this->ref->findByCode($code);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('user-1', $row['user_id']);
    }

    public function testFindByCodeReturnsNullForUnknown(): void
    {
        $this->assertNull($this->ref->findByCode('XXXXXXXX'));
    }

    public function testFindByCodeIsCaseInsensitive(): void
    {
        $code = $this->ref->getOrCreate('user-1');
        $this->assertNotNull($this->ref->findByCode(strtolower($code)));
    }

    // ── findByUser ────────────────────────────────────────────────────────────

    public function testFindByUserReturnsNullIfNoCode(): void
    {
        $this->assertNull($this->ref->findByUser('nobody'));
    }

    // ── convert ───────────────────────────────────────────────────────────────

    public function testConvertReturnsTrueOnFirstConversion(): void
    {
        $code = $this->ref->getOrCreate('user-1');
        $this->assertTrue($this->ref->convert($code, 'new-user-1'));
    }

    public function testConvertIsIdempotentForSameReferredUser(): void
    {
        $code = $this->ref->getOrCreate('user-1');
        $this->ref->convert($code, 'new-user-1');
        $this->assertFalse($this->ref->convert($code, 'new-user-1'));
    }

    public function testConvertThrowsForUnknownCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ref->convert('XXXXXXXX', 'new-user-1');
    }

    public function testConvertRecordsMultipleReferrals(): void
    {
        $code = $this->ref->getOrCreate('user-1');
        $this->ref->convert($code, 'new-a');
        $this->ref->convert($code, 'new-b');
        $this->assertSame(2, $this->ref->conversionCount('user-1'));
    }

    // ── conversions ───────────────────────────────────────────────────────────

    public function testConversionsReturnsEmptyForUserWithNoCode(): void
    {
        $this->assertSame([], $this->ref->conversions('nobody'));
    }

    public function testConversionsReturnsAllReferrals(): void
    {
        $code = $this->ref->getOrCreate('user-1');
        $this->ref->convert($code, 'new-a');
        $this->ref->convert($code, 'new-b');
        $list = $this->ref->conversions('user-1');
        $this->assertCount(2, $list);
    }

    // ── conversionCount ───────────────────────────────────────────────────────

    public function testConversionCountReturnsZeroForNewUser(): void
    {
        $this->ref->getOrCreate('user-1');
        $this->assertSame(0, $this->ref->conversionCount('user-1'));
    }

    public function testConversionCountReturnsCorrectNumber(): void
    {
        $code = $this->ref->getOrCreate('user-1');
        $this->ref->convert($code, 'new-a');
        $this->ref->convert($code, 'new-b');
        $this->assertSame(2, $this->ref->conversionCount('user-1'));
    }
}
