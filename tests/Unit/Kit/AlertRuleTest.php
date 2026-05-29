<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\AlertRule;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AlertRule.
 */
final class AlertRuleTest extends TestCase
{
    private PDO $db;
    private AlertRule $ar;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE alert_rules (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                name              VARCHAR(255) NOT NULL UNIQUE,
                metric            VARCHAR(255) NOT NULL,
                threshold         DOUBLE       NOT NULL DEFAULT 0,
                condition_op      VARCHAR(10)  NOT NULL DEFAULT \'gt\',
                enabled           TINYINT(1)   NOT NULL DEFAULT 1,
                last_triggered_at DATETIME     DEFAULT NULL,
                created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->db->exec('
            CREATE TABLE alert_events (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                rule_id      INTEGER NOT NULL,
                metric_value DOUBLE  NOT NULL,
                triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->ar = new AlertRule($this->db);
    }

    // ── define ────────────────────────────────────────────────────────────────

    public function testDefineReturnsId(): void
    {
        $id = $this->ar->define('cpu-high', 'cpu_percent', 90.0, 'gte');
        $this->assertGreaterThan(0, $id);
    }

    public function testDefineIsUpsert(): void
    {
        $this->ar->define('cpu-high', 'cpu_percent', 90.0, 'gte');
        $this->ar->define('cpu-high', 'cpu_percent', 95.0, 'gt');
        $rule = $this->ar->find('cpu-high');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame(95.0, (float)$rule['threshold']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('gt', $rule['condition_op']);
    }

    public function testDefineThrowsOnInvalidCondition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ar->define('r', 'm', 1.0, 'invalid');
    }

    public function testDefineThrowsOnEmptyMetric(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ar->define('r', '', 1.0);
    }

    // ── evaluate conditions ───────────────────────────────────────────────────

    public function testEvaluateGt(): void
    {
        $this->ar->define('r', 'm', 90.0, 'gt');
        $this->assertTrue($this->ar->evaluate('r', 91.0));
        $this->assertFalse($this->ar->evaluate('r', 90.0));
        $this->assertFalse($this->ar->evaluate('r', 89.0));
    }

    public function testEvaluateGte(): void
    {
        $this->ar->define('r', 'm', 90.0, 'gte');
        $this->assertTrue($this->ar->evaluate('r', 90.0));
        $this->assertFalse($this->ar->evaluate('r', 89.9));
    }

    public function testEvaluateLt(): void
    {
        $this->ar->define('r', 'm', 10.0, 'lt');
        $this->assertTrue($this->ar->evaluate('r', 9.9));
        $this->assertFalse($this->ar->evaluate('r', 10.0));
    }

    public function testEvaluateLte(): void
    {
        $this->ar->define('r', 'm', 10.0, 'lte');
        $this->assertTrue($this->ar->evaluate('r', 10.0));
        $this->assertFalse($this->ar->evaluate('r', 10.1));
    }

    public function testEvaluateEq(): void
    {
        $this->ar->define('r', 'm', 42.0, 'eq');
        $this->assertTrue($this->ar->evaluate('r', 42.0));
        $this->assertFalse($this->ar->evaluate('r', 43.0));
    }

    // ── evaluate side-effects ─────────────────────────────────────────────────

    public function testEvaluateLogsEventOnBreach(): void
    {
        $this->ar->define('cpu-high', 'cpu', 90.0, 'gt');
        $this->ar->evaluate('cpu-high', 95.0);
        $history = $this->ar->history('cpu-high', 10);
        $this->assertCount(1, $history);
        $this->assertSame(95.0, (float)$history[0]['metric_value']);
    }

    public function testEvaluateDoesNotLogOnNoBreach(): void
    {
        $this->ar->define('cpu-high', 'cpu', 90.0, 'gt');
        $this->ar->evaluate('cpu-high', 80.0);
        $this->assertSame([], $this->ar->history('cpu-high'));
    }

    public function testEvaluateUpdatesLastTriggeredAt(): void
    {
        $this->ar->define('cpu-high', 'cpu', 90.0, 'gt');
        $this->ar->evaluate('cpu-high', 95.0);
        $rule = $this->ar->find('cpu-high');
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNotNull($rule['last_triggered_at']);
    }

    public function testEvaluateReturnsFalseForMissingRule(): void
    {
        $this->assertFalse($this->ar->evaluate('nonexistent', 100.0));
    }

    // ── enable / disable ──────────────────────────────────────────────────────

    public function testDisablePreventsBreach(): void
    {
        $this->ar->define('cpu-high', 'cpu', 90.0, 'gt');
        $this->ar->disable('cpu-high');
        $this->assertFalse($this->ar->evaluate('cpu-high', 99.0));
        $this->assertSame([], $this->ar->history('cpu-high'));
    }

    public function testEnableReEnablesRule(): void
    {
        $this->ar->define('cpu-high', 'cpu', 90.0, 'gt');
        $this->ar->disable('cpu-high');
        $this->ar->enable('cpu-high');
        $this->assertTrue($this->ar->evaluate('cpu-high', 95.0));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsAllRules(): void
    {
        $this->ar->define('r1', 'm', 1.0);
        $this->ar->define('r2', 'm', 2.0);
        $this->assertCount(2, $this->ar->all());
    }

    public function testAllFilterByEnabled(): void
    {
        $this->ar->define('r1', 'm', 1.0);
        $this->ar->define('r2', 'm', 2.0);
        $this->ar->disable('r2');
        $this->assertCount(1, $this->ar->all(true));
        $this->assertCount(1, $this->ar->all(false));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesRuleAndEvents(): void
    {
        $this->ar->define('cpu-high', 'cpu', 90.0, 'gt');
        $this->ar->evaluate('cpu-high', 95.0);
        $this->assertTrue($this->ar->delete('cpu-high'));
        $this->assertNull($this->ar->find('cpu-high'));
        $this->assertSame([], $this->ar->history('cpu-high'));
    }

    public function testDeleteReturnsFalseForMissing(): void
    {
        $this->assertFalse($this->ar->delete('nope'));
    }
}
