<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\DataRetention;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DataRetention.
 */
final class DataRetentionTest extends TestCase
{
    private PDO $db;
    private DataRetention $ret;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('
            CREATE TABLE retention_policies (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                table_name VARCHAR(100) NOT NULL,
                ttl_days   INTEGER      NOT NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (table_name)
            )
        ');
        $this->ret = new DataRetention($this->db);
    }

    // ── policy management ───────────────────────────────────────────────────────

    public function testSetAndGetPolicy(): void
    {
        $this->ret->setPolicy('access_logs', 90);
        $this->assertSame(90, $this->ret->policyFor('access_logs'));
    }

    public function testPolicyForUnknownIsNull(): void
    {
        $this->assertNull($this->ret->policyFor('nope'));
    }

    public function testSetPolicyIsIdempotentAndUpdates(): void
    {
        $this->ret->setPolicy('access_logs', 90);
        $this->ret->setPolicy('access_logs', 30);
        $this->assertSame(30, $this->ret->policyFor('access_logs'));
        $this->assertCount(1, $this->ret->policies());
    }

    public function testRemovePolicy(): void
    {
        $this->ret->setPolicy('access_logs', 90);
        $this->ret->removePolicy('access_logs');
        $this->assertNull($this->ret->policyFor('access_logs'));
    }

    public function testRemovePolicyMissingIsNoop(): void
    {
        $this->ret->removePolicy('never'); // no throw
        $this->assertSame([], $this->ret->policies());
    }

    public function testPoliciesOrderedByName(): void
    {
        $this->ret->setPolicy('page_views', 30);
        $this->ret->setPolicy('access_logs', 90);
        $list = $this->ret->policies();
        $this->assertSame('access_logs', $list[0]['table']);
        $this->assertSame('page_views', $list[1]['table']);
    }

    // ── cutoff / due ──────────────────────────────────────────────────────────

    public function testCutoffSubtractsTtlDays(): void
    {
        $this->ret->setPolicy('access_logs', 90);
        // asOf 2026-06-01 00:00:00 − 90 days = 2026-03-03 00:00:00
        $this->assertSame('2026-03-03 00:00:00', $this->ret->cutoff('access_logs', '2026-06-01 00:00:00'));
    }

    public function testCutoffNullWhenNoPolicy(): void
    {
        $this->assertNull($this->ret->cutoff('access_logs', '2026-06-01'));
    }

    public function testDueReturnsAllPoliciesWithCutoff(): void
    {
        $this->ret->setPolicy('access_logs', 90);
        $this->ret->setPolicy('page_views', 30);
        $due = $this->ret->due('2026-06-01 00:00:00');
        $this->assertCount(2, $due);
        // ordered by name → access_logs first; 90-day cutoff
        $this->assertSame('access_logs', $due[0]['table']);
        $this->assertSame('2026-03-03 00:00:00', $due[0]['cutoff']);
        // page_views: 2026-06-01 − 30 days = 2026-05-02
        $this->assertSame('2026-05-02 00:00:00', $due[1]['cutoff']);
    }

    // ── purge ─────────────────────────────────────────────────────────────────

    public function testPurgeDeletesOnlyRowsOlderThanCutoff(): void
    {
        $this->db->exec('CREATE TABLE access_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at DATETIME)');
        // TTL 30 days, asOf 2026-06-01 → cutoff 2026-05-02 00:00:00 (strictly-older purged)
        $this->ret->setPolicy('access_logs', 30);
        $ins = $this->db->prepare('INSERT INTO access_logs (created_at) VALUES (?)');
        $ins->execute(['2026-05-01 12:00:00']); // before cutoff  → purged
        $ins->execute(['2026-05-02 00:00:00']); // exactly cutoff → kept (not strictly <)
        $ins->execute(['2026-05-15 00:00:00']); // after cutoff   → kept

        $deleted = $this->ret->purge('access_logs', 'created_at', '2026-06-01 00:00:00');

        $this->assertSame(1, $deleted);
        $this->assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM access_logs')->fetchColumn());
    }

    public function testPurgeThrowsWithoutPolicy(): void
    {
        $this->db->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY, created_at DATETIME)');
        $this->expectException(\InvalidArgumentException::class);
        $this->ret->purge('foo', 'created_at', '2026-06-01');
    }

    // ── identifier validation ───────────────────────────────────────────────────

    public function testSetPolicyRejectsBadIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ret->setPolicy('access_logs; DROP TABLE users', 30);
    }

    public function testPurgeRejectsBadColumnIdentifier(): void
    {
        $this->ret->setPolicy('access_logs', 30);
        $this->db->exec('CREATE TABLE access_logs (id INTEGER PRIMARY KEY, created_at DATETIME)');
        $this->expectException(\InvalidArgumentException::class);
        $this->ret->purge('access_logs', 'created_at = 1 OR 1', '2026-06-01');
    }

    public function testSetPolicyRejectsZeroTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->ret->setPolicy('access_logs', 0);
    }
}
