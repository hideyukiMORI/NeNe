<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\AppVersion;
use PDO;
use PHPUnit\Framework\TestCase;

final class AppVersionTest extends TestCase
{
    private PDO $pdo;
    private AppVersion $av;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE app_versions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                version     VARCHAR(50)  NOT NULL,
                environment VARCHAR(50)  NOT NULL DEFAULT \'production\',
                git_hash    VARCHAR(40)  NULL,
                deployed_by VARCHAR(255) NULL,
                note        TEXT         NULL,
                deployed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->av = new AppVersion($this->pdo);
    }

    // ── record / find ─────────────────────────────────────────────────────────

    public function testRecordReturnsId(): void
    {
        $id = $this->av->record('1.0.0');
        $this->assertGreaterThan(0, $id);
    }

    public function testRecordStoresAllFields(): void
    {
        $id  = $this->av->record('1.2.3', 'staging', 'abc123', 'ci-bot', 'Hotfix');
        $row = $this->av->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('1.2.3', $row['version']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('staging', $row['environment']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('abc123', $row['git_hash']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('ci-bot', $row['deployed_by']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('Hotfix', $row['note']);
    }

    public function testRecordUsesProductionAsDefaultEnv(): void
    {
        $id  = $this->av->record('1.0.0');
        $row = $this->av->find($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('production', $row['environment']);
    }

    public function testRecordThrowsOnEmptyVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->av->record('');
    }

    // ── current ───────────────────────────────────────────────────────────────

    public function testCurrentReturnsLatestForEnvironment(): void
    {
        $id1 = $this->av->record('1.0.0', 'production');
        $id2 = $this->av->record('1.1.0', 'production');
        $this->av->record('2.0.0', 'staging');

        $row = $this->av->current('production');
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id2, (int)$row['id']);
    }

    public function testCurrentReturnsNullWhenNoRecords(): void
    {
        $this->assertNull($this->av->current('production'));
    }

    // ── history ───────────────────────────────────────────────────────────────

    public function testHistoryReturnsNewestFirst(): void
    {
        $id1 = $this->av->record('1.0', 'prod');
        $id2 = $this->av->record('1.1', 'prod');
        $id3 = $this->av->record('1.2', 'prod');

        $history = $this->av->history('prod');
        $this->assertSame($id3, (int)$history[0]['id']);
        $this->assertSame($id2, (int)$history[1]['id']);
        $this->assertSame($id1, (int)$history[2]['id']);
    }

    public function testHistoryRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->av->record("1.{$i}", 'prod');
        }
        $history = $this->av->history('prod', 3);
        $this->assertCount(3, $history);
    }

    public function testHistoryIsolatedByEnvironment(): void
    {
        $this->av->record('1.0', 'prod');
        $this->av->record('2.0', 'staging');

        $this->assertCount(1, $this->av->history('prod'));
        $this->assertCount(1, $this->av->history('staging'));
    }

    // ── environments ─────────────────────────────────────────────────────────

    public function testEnvironmentsReturnsSortedDistinctList(): void
    {
        $this->av->record('1.0', 'staging');
        $this->av->record('1.0', 'production');
        $this->av->record('1.1', 'production');

        $envs = $this->av->environments();
        $this->assertSame(['production', 'staging'], $envs);
    }

    public function testEnvironmentsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], $this->av->environments());
    }

    // ── clearEnvironment ──────────────────────────────────────────────────────

    public function testClearEnvironmentDeletesRecords(): void
    {
        $this->av->record('1.0', 'prod');
        $this->av->record('1.1', 'prod');
        $this->av->record('2.0', 'staging');

        $count = $this->av->clearEnvironment('prod');
        $this->assertSame(2, $count);
        $this->assertSame([], $this->av->history('prod'));
        $this->assertCount(1, $this->av->history('staging'));
    }
}
