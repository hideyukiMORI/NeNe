<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\RedactionRule;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RedactionRule.
 */
final class RedactionRuleTest extends TestCase
{
    private PDO $db;
    private RedactionRule $r;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE redaction_rules (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        VARCHAR(100) NOT NULL,
                pattern     TEXT         NOT NULL,
                replacement VARCHAR(255) NOT NULL DEFAULT \'[REDACTED]\',
                priority    INTEGER      NOT NULL DEFAULT 0,
                enabled     INTEGER      NOT NULL DEFAULT 1,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (name)
            )
        ');
        $this->r = new RedactionRule($this->db);
    }

    public function testRedactAppliesRule(): void
    {
        $this->r->addRule('card', '/\b\d{13,16}\b/', '[CARD]');
        $this->assertSame('pay [CARD] now', $this->r->redact('pay 4111111111111111 now'));
    }

    public function testRedactAppliesMultipleRules(): void
    {
        $this->r->addRule('card', '/\b\d{13,16}\b/', '[CARD]');
        $this->r->addRule('email', '/[\w.+-]+@[\w-]+\.[\w.-]+/', '[EMAIL]');
        $this->assertSame(
            'card [CARD] mail [EMAIL]',
            $this->r->redact('card 4111111111111111 mail a@b.com')
        );
    }

    public function testPriorityOrder(): void
    {
        // Higher priority runs first.
        $this->r->addRule('digits', '/\d+/', 'N', priority: 1);
        $this->r->addRule('specific', '/4111/', 'CARD', priority: 10);
        // 'specific' (pri 10) first → 'CARD111111111111', then digits → 'CARDN'
        $this->assertSame('CARDN', $this->r->redact('4111111111111111'));
    }

    public function testDisabledRuleNotApplied(): void
    {
        $this->r->addRule('email', '/[\w.+-]+@[\w-]+\.[\w.-]+/', '[EMAIL]');
        $this->r->disable('email');
        $this->assertSame('mail a@b.com', $this->r->redact('mail a@b.com'));
    }

    public function testReEnable(): void
    {
        $this->r->addRule('email', '/[\w.+-]+@[\w-]+\.[\w.-]+/', '[EMAIL]');
        $this->r->disable('email');
        $this->r->enable('email');
        $this->assertSame('mail [EMAIL]', $this->r->redact('mail a@b.com'));
    }

    public function testApplyRuleIgnoresEnabledFlag(): void
    {
        $this->r->addRule('email', '/[\w.+-]+@[\w-]+\.[\w.-]+/', '[EMAIL]');
        $this->r->disable('email');
        $this->assertSame('mail [EMAIL]', $this->r->applyRule('email', 'mail a@b.com'));
    }

    public function testApplyRuleMissingReturnsUnchanged(): void
    {
        $this->assertSame('hello', $this->r->applyRule('ghost', 'hello'));
    }

    public function testAddRuleIsIdempotent(): void
    {
        $this->r->addRule('card', '/\d{16}/', '[A]');
        $this->r->addRule('card', '/\d{16}/', '[B]');
        $this->assertCount(1, $this->r->rules());
        $this->assertSame('[B]', $this->r->rules()[0]['replacement']);
    }

    public function testRulesOrderedByPriorityThenName(): void
    {
        $this->r->addRule('b', '/x/', 'X', priority: 5);
        $this->r->addRule('a', '/y/', 'Y', priority: 5);
        $this->r->addRule('c', '/z/', 'Z', priority: 10);
        $names = array_map(static fn (array $rr): string => $rr['name'], $this->r->rules());
        $this->assertSame(['c', 'a', 'b'], $names);
    }

    public function testRemove(): void
    {
        $this->r->addRule('card', '/\d{16}/', '[CARD]');
        $this->r->remove('card');
        $this->assertSame([], $this->r->rules());
    }

    public function testRedactWithNoRulesReturnsInput(): void
    {
        $this->assertSame('untouched', $this->r->redact('untouched'));
    }

    public function testAddRuleRejectsInvalidPattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->addRule('bad', '/unterminated(');
    }

    public function testAddRuleRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->r->addRule('  ', '/x/');
    }
}
