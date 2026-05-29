<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\PasswordPolicy;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PasswordPolicy.
 */
final class PasswordPolicyTest extends TestCase
{
    private PDO $db;
    private PasswordPolicy $pp;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE password_policies (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                scope          VARCHAR(100) NOT NULL,
                min_length     INTEGER      NOT NULL DEFAULT 8,
                require_upper  INTEGER      NOT NULL DEFAULT 0,
                require_lower  INTEGER      NOT NULL DEFAULT 0,
                require_digit  INTEGER      NOT NULL DEFAULT 0,
                require_symbol INTEGER      NOT NULL DEFAULT 0,
                updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (scope)
            )
        ');
        $this->pp = new PasswordPolicy($this->db);
    }

    public function testDefaultPolicyWhenNoneSet(): void
    {
        // Default = min length 8, no class requirements.
        $this->assertSame([], $this->pp->validate('web', 'abcdefgh')); // exactly 8 → ok
        $this->assertSame(
            [PasswordPolicy::VIOLATION_TOO_SHORT],
            $this->pp->validate('web', 'abcdefg') // 7 → too short
        );
    }

    public function testGetPolicyReturnsDefaultShape(): void
    {
        $p = $this->pp->getPolicy('web');
        $this->assertSame(8, $p['min_length']);
        $this->assertFalse($p['require_upper']);
    }

    public function testSetAndGetPolicy(): void
    {
        $this->pp->setPolicy('admin', minLength: 12, requireUpper: true, requireDigit: true, requireSymbol: true);
        $p = $this->pp->getPolicy('admin');
        $this->assertSame(12, $p['min_length']);
        $this->assertTrue($p['require_upper']);
        $this->assertTrue($p['require_digit']);
        $this->assertTrue($p['require_symbol']);
        $this->assertFalse($p['require_lower']);
    }

    public function testValidateCollectsAllViolations(): void
    {
        $this->pp->setPolicy('admin', minLength: 12, requireUpper: true, requireDigit: true, requireSymbol: true);
        $v = $this->pp->validate('admin', 'short');
        $this->assertContains(PasswordPolicy::VIOLATION_TOO_SHORT, $v);
        $this->assertContains(PasswordPolicy::VIOLATION_NEED_UPPER, $v);
        $this->assertContains(PasswordPolicy::VIOLATION_NEED_DIGIT, $v);
        $this->assertContains(PasswordPolicy::VIOLATION_NEED_SYMBOL, $v);
        $this->assertNotContains(PasswordPolicy::VIOLATION_NEED_LOWER, $v); // 'short' has lowercase
    }

    public function testIsValidPassesStrongPassword(): void
    {
        $this->pp->setPolicy('admin', minLength: 12, requireUpper: true, requireLower: true, requireDigit: true, requireSymbol: true);
        $this->assertTrue($this->pp->isValid('admin', 'Sup3rSecret!'));
    }

    public function testMinLengthBoundary(): void
    {
        $this->pp->setPolicy('x', minLength: 10);
        $this->assertFalse($this->pp->isValid('x', '123456789'));   // 9  → too short
        $this->assertTrue($this->pp->isValid('x', '1234567890'));   // 10 → exactly at minimum, ok
    }

    public function testRequireSymbolDetectsNonAlnum(): void
    {
        $this->pp->setPolicy('x', minLength: 1, requireSymbol: true);
        $this->assertFalse($this->pp->isValid('x', 'abc123'));
        $this->assertTrue($this->pp->isValid('x', 'abc-123'));
    }

    public function testMultibyteLengthCountedByCharacters(): void
    {
        $this->pp->setPolicy('x', minLength: 4);
        $this->assertTrue($this->pp->isValid('x', 'パスワード')); // 5 chars
        $this->assertFalse($this->pp->isValid('x', 'パス'));      // 2 chars
    }

    public function testSetPolicyIsIdempotent(): void
    {
        $this->pp->setPolicy('admin', minLength: 10);
        $this->pp->setPolicy('admin', minLength: 16, requireUpper: true);
        $this->assertSame(16, $this->pp->getPolicy('admin')['min_length']);
        $this->assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM password_policies')->fetchColumn());
    }

    public function testRemoveRevertsToDefault(): void
    {
        $this->pp->setPolicy('admin', minLength: 20);
        $this->pp->remove('admin');
        $this->assertSame(8, $this->pp->getPolicy('admin')['min_length']); // back to default
    }

    public function testSetPolicyRejectsEmptyScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pp->setPolicy('  ', minLength: 8);
    }

    public function testSetPolicyRejectsZeroMinLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pp->setPolicy('x', minLength: 0);
    }
}
