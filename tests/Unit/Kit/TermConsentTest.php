<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\TermConsent;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TermConsent.
 */
final class TermConsentTest extends TestCase
{
    private PDO $db;
    private TermConsent $tc;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE term_consents (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     VARCHAR(255) NOT NULL,
                term_name   VARCHAR(100) NOT NULL,
                version     VARCHAR(50)  NOT NULL,
                accepted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address  VARCHAR(45)  NOT NULL DEFAULT \'\',
                UNIQUE (user_id, term_name, version)
            )
        ');
        $this->tc = new TermConsent($this->db);
    }

    // ── accept ────────────────────────────────────────────────────────────────

    public function testAcceptReturnsTrueOnFirstAcceptance(): void
    {
        $this->assertTrue($this->tc->accept('user-1', 'tos', '2.0'));
    }

    public function testAcceptIsIdempotent(): void
    {
        $this->tc->accept('user-1', 'tos', '2.0');
        $this->assertFalse($this->tc->accept('user-1', 'tos', '2.0'));
    }

    public function testAcceptDifferentVersionsAreDistinct(): void
    {
        $this->assertTrue($this->tc->accept('user-1', 'tos', '1.0'));
        $this->assertTrue($this->tc->accept('user-1', 'tos', '2.0'));
    }

    public function testAcceptStoresIpAddress(): void
    {
        $this->tc->accept('user-1', 'tos', '2.0', ipAddress: '1.2.3.4');
        $all = $this->tc->all('user-1');
        $this->assertSame('1.2.3.4', $all[0]['ip_address']);
    }

    public function testAcceptThrowsOnEmptyTermName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tc->accept('user-1', '', '2.0');
    }

    public function testAcceptThrowsOnEmptyVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tc->accept('user-1', 'tos', '');
    }

    // ── hasAccepted ───────────────────────────────────────────────────────────

    public function testHasAcceptedReturnsFalseBeforeAcceptance(): void
    {
        $this->assertFalse($this->tc->hasAccepted('user-1', 'tos', '2.0'));
    }

    public function testHasAcceptedReturnsTrueAfterAcceptance(): void
    {
        $this->tc->accept('user-1', 'tos', '2.0');
        $this->assertTrue($this->tc->hasAccepted('user-1', 'tos', '2.0'));
    }

    public function testHasAcceptedReturnsFalseForDifferentVersion(): void
    {
        $this->tc->accept('user-1', 'tos', '1.0');
        $this->assertFalse($this->tc->hasAccepted('user-1', 'tos', '2.0'));
    }

    public function testHasAcceptedIsUserScoped(): void
    {
        $this->tc->accept('user-1', 'tos', '2.0');
        $this->assertFalse($this->tc->hasAccepted('user-2', 'tos', '2.0'));
    }

    // ── acceptedVersion ───────────────────────────────────────────────────────

    public function testAcceptedVersionReturnsNullIfNone(): void
    {
        $this->assertNull($this->tc->acceptedVersion('user-1', 'tos'));
    }

    public function testAcceptedVersionReturnsMostRecent(): void
    {
        $this->tc->accept('user-1', 'tos', '1.0');
        $this->tc->accept('user-1', 'tos', '2.0');
        $this->assertSame('2.0', $this->tc->acceptedVersion('user-1', 'tos'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsAllAcceptances(): void
    {
        $this->tc->accept('user-1', 'tos', '2.0');
        $this->tc->accept('user-1', 'privacy', '1.0');
        $this->assertCount(2, $this->tc->all('user-1'));
    }

    public function testAllReturnsEmptyForNewUser(): void
    {
        $this->assertSame([], $this->tc->all('nobody'));
    }

    public function testAllIsUserScoped(): void
    {
        $this->tc->accept('user-1', 'tos', '2.0');
        $this->tc->accept('user-2', 'tos', '2.0');
        $this->assertCount(1, $this->tc->all('user-1'));
    }

    // ── acceptances ───────────────────────────────────────────────────────────

    public function testAcceptancesReturnsAllUsersForTermVersion(): void
    {
        $this->tc->accept('user-1', 'tos', '2.0');
        $this->tc->accept('user-2', 'tos', '2.0');
        $this->assertCount(2, $this->tc->acceptances('tos', '2.0'));
    }

    public function testAcceptancesIsVersionScoped(): void
    {
        $this->tc->accept('user-1', 'tos', '1.0');
        $this->tc->accept('user-2', 'tos', '2.0');
        $this->assertCount(1, $this->tc->acceptances('tos', '2.0'));
    }
}
