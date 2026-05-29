<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\Referral;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Referral.
 */
final class ReferralTest extends TestCase
{
    private PDO $db;
    private Referral $ref;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE referral_codes (
                user_id    VARCHAR(255) NOT NULL PRIMARY KEY,
                code       VARCHAR(20)  NOT NULL UNIQUE,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE referrals (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                referred_id  VARCHAR(255) NOT NULL UNIQUE,
                referrer_id  VARCHAR(255) NOT NULL,
                converted    TINYINT(1)   NOT NULL DEFAULT 0,
                converted_at DATETIME     DEFAULT NULL,
                created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ref = new Referral($this->db);
    }

    // ── codeFor ───────────────────────────────────────────────────────────────

    public function testCodeForGeneratesCode(): void
    {
        $code = $this->ref->codeFor('user-1');
        $this->assertSame(8, strlen($code));
    }

    public function testCodeForIsIdempotent(): void
    {
        $code1 = $this->ref->codeFor('user-1');
        $code2 = $this->ref->codeFor('user-1');
        $this->assertSame($code1, $code2);
    }

    public function testCodeForGeneratesDifferentCodesForDifferentUsers(): void
    {
        $code1 = $this->ref->codeFor('user-1');
        $code2 = $this->ref->codeFor('user-2');
        $this->assertNotSame($code1, $code2);
    }

    public function testCodeForThrowsOnEmptyUserId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ref->codeFor('');
    }

    // ── ownerOf ───────────────────────────────────────────────────────────────

    public function testOwnerOfReturnsUserId(): void
    {
        $code = $this->ref->codeFor('user-1');
        $this->assertSame('user-1', $this->ref->ownerOf($code));
    }

    public function testOwnerOfReturnsNullForInvalidCode(): void
    {
        $this->assertNull($this->ref->ownerOf('INVALID'));
    }

    // ── attribute ─────────────────────────────────────────────────────────────

    public function testAttributeRecordsReferral(): void
    {
        $code = $this->ref->codeFor('user-1');
        $this->assertTrue($this->ref->attribute('user-2', $code));
        $referral = $this->ref->getReferral('user-2');
        $this->assertSame('user-1', $referral['referrer_id']);
    }

    public function testAttributeIsIdempotent(): void
    {
        $code = $this->ref->codeFor('user-1');
        $this->ref->attribute('user-2', $code);
        $this->assertFalse($this->ref->attribute('user-2', $code));
    }

    public function testAttributeThrowsOnSelfReferral(): void
    {
        $code = $this->ref->codeFor('user-1');
        $this->expectException(\InvalidArgumentException::class);
        $this->ref->attribute('user-1', $code);
    }

    public function testAttributeThrowsOnInvalidCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ref->attribute('user-2', 'BADCODE1');
    }

    // ── convert ───────────────────────────────────────────────────────────────

    public function testConvertMarksConversion(): void
    {
        $code = $this->ref->codeFor('user-1');
        $this->ref->attribute('user-2', $code);
        $this->assertTrue($this->ref->convert('user-2'));
        $referral = $this->ref->getReferral('user-2');
        $this->assertTrue($referral['converted']);
    }

    public function testConvertReturnsFalseIfAlreadyConverted(): void
    {
        $code = $this->ref->codeFor('user-1');
        $this->ref->attribute('user-2', $code);
        $this->ref->convert('user-2');
        $this->assertFalse($this->ref->convert('user-2'));
    }

    public function testConvertReturnsFalseIfNotReferred(): void
    {
        $this->assertFalse($this->ref->convert('user-99'));
    }

    // ── stats ─────────────────────────────────────────────────────────────────

    public function testStatsReturnsCorrectCounts(): void
    {
        $code = $this->ref->codeFor('user-1');
        $this->ref->attribute('user-2', $code);
        $this->ref->attribute('user-3', $code);
        $this->ref->convert('user-2');

        $stats = $this->ref->stats('user-1');
        $this->assertSame(2, $stats['referrals']);
        $this->assertSame(1, $stats['conversions']);
    }

    public function testStatsReturnsZerosForUserWithNoReferrals(): void
    {
        $stats = $this->ref->stats('nobody');
        $this->assertSame(0, $stats['referrals']);
        $this->assertSame(0, $stats['conversions']);
    }

    // ── listReferrals ─────────────────────────────────────────────────────────

    public function testListReferralsReturnsReferredUsers(): void
    {
        $code = $this->ref->codeFor('user-1');
        $this->ref->attribute('user-2', $code);
        $this->ref->attribute('user-3', $code);
        $list = $this->ref->listReferrals('user-1');
        $this->assertCount(2, $list);
    }

    public function testListReferralsReturnsEmptyForNoReferrals(): void
    {
        $this->assertSame([], $this->ref->listReferrals('nobody'));
    }

    // ── getReferral ───────────────────────────────────────────────────────────

    public function testGetReferralReturnsNullForNonReferred(): void
    {
        $this->assertNull($this->ref->getReferral('user-99'));
    }
}
