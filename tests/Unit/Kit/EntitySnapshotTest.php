<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\EntitySnapshot;
use PDO;
use PHPUnit\Framework\TestCase;

final class EntitySnapshotTest extends TestCase
{
    private PDO $pdo;
    private EntitySnapshot $es;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE entity_snapshots (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type VARCHAR(100) NOT NULL,
                entity_id   VARCHAR(255) NOT NULL,
                label       VARCHAR(100) NULL,
                data        TEXT         NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');
        $this->es = new EntitySnapshot($this->pdo);
    }

    // ── save ──────────────────────────────────────────────────────────────────

    public function testSaveReturnsId(): void
    {
        $id = $this->es->save('article', '42', ['title' => 'Hello']);
        $this->assertGreaterThan(0, $id);
    }

    public function testSaveStoresData(): void
    {
        $id  = $this->es->save('article', '42', ['title' => 'Hello'], 'v1');
        $row = $this->es->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $data = json_decode((string)$row['data'], true);
        $this->assertSame('Hello', $data['title']);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame('v1', $row['label']);
    }

    public function testSaveAllowsNullLabel(): void
    {
        $id  = $this->es->save('article', '42', ['title' => 'Hello']);
        $row = $this->es->get($id);
        $this->assertNotNull($row);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertNull($row['label']);
    }

    public function testSaveThrowsOnEmptyEntityType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->es->save('', '42', ['title' => 'Hello']);
    }

    public function testSaveThrowsOnEmptyEntityId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->es->save('article', '', ['title' => 'Hello']);
    }

    public function testSaveThrowsOnEmptyData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->es->save('article', '42', []);
    }

    // ── get ───────────────────────────────────────────────────────────────────

    public function testGetReturnsNullForMissingId(): void
    {
        $this->assertNull($this->es->get(9999));
    }

    // ── latest ────────────────────────────────────────────────────────────────

    public function testLatestReturnsNewestSnapshot(): void
    {
        $id1 = $this->es->save('article', '42', ['version' => 1]);
        $id2 = $this->es->save('article', '42', ['version' => 2]);
        $latest = $this->es->latest('article', '42');
        $this->assertNotNull($latest);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $this->assertSame($id2, (int)$latest['id']);
    }

    public function testLatestReturnsNullWhenNone(): void
    {
        $this->assertNull($this->es->latest('article', '999'));
    }

    // ── findAt ────────────────────────────────────────────────────────────────

    public function testFindAtReturnsSnapshotBeforeOrAtDatetime(): void
    {
        $this->pdo->exec("INSERT INTO entity_snapshots (entity_type, entity_id, data, created_at)
                          VALUES ('article', '42', '{\"v\":1}', '2026-01-01 10:00:00')");
        $this->pdo->exec("INSERT INTO entity_snapshots (entity_type, entity_id, data, created_at)
                          VALUES ('article', '42', '{\"v\":2}', '2026-06-01 10:00:00')");
        $snap = $this->es->findAt('article', '42', '2026-03-01 00:00:00');
        $this->assertNotNull($snap);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $data = json_decode((string)$snap['data'], true);
        $this->assertSame(1, $data['v']);
    }

    public function testFindAtReturnsNullWhenNoneBeforeDatetime(): void
    {
        $this->pdo->exec("INSERT INTO entity_snapshots (entity_type, entity_id, data, created_at)
                          VALUES ('article', '42', '{\"v\":1}', '2026-12-01 10:00:00')");
        $snap = $this->es->findAt('article', '42', '2026-01-01 00:00:00');
        $this->assertNull($snap);
    }

    // ── findByLabel ───────────────────────────────────────────────────────────

    public function testFindByLabelReturnsMatchingSnapshot(): void
    {
        $this->es->save('article', '42', ['v' => 1], 'v1');
        $this->es->save('article', '42', ['v' => 2], 'v2');
        $snap = $this->es->findByLabel('article', '42', 'v1');
        $this->assertNotNull($snap);
        // @phan-suppress-next-line PhanTypeArraySuspiciousNullable
        $data = json_decode((string)$snap['data'], true);
        $this->assertSame(1, $data['v']);
    }

    public function testFindByLabelReturnsNullForUnknownLabel(): void
    {
        $this->assertNull($this->es->findByLabel('article', '42', 'nonexistent'));
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function testListReturnsNewestFirst(): void
    {
        $id1 = $this->es->save('article', '42', ['v' => 1]);
        $id2 = $this->es->save('article', '42', ['v' => 2]);
        $list = $this->es->list('article', '42');
        $this->assertCount(2, $list);
        $this->assertSame($id2, (int)$list[0]['id']);
    }

    public function testListIsIsolatedByEntity(): void
    {
        $this->es->save('article', '42', ['v' => 1]);
        $this->es->save('article', '99', ['v' => 1]);
        $this->assertCount(1, $this->es->list('article', '42'));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesSnapshot(): void
    {
        $id = $this->es->save('article', '42', ['v' => 1]);
        $this->assertTrue($this->es->delete($id));
        $this->assertNull($this->es->get($id));
    }

    public function testDeleteReturnsFalseForMissingId(): void
    {
        $this->assertFalse($this->es->delete(9999));
    }

    // ── purgeOlderThan ────────────────────────────────────────────────────────

    public function testPurgeOlderThanDeletesOldSnapshots(): void
    {
        $this->pdo->exec("INSERT INTO entity_snapshots (entity_type, entity_id, data, created_at)
                          VALUES ('article', '42', '{\"v\":0}', '2020-01-01 00:00:00')");
        $this->es->save('article', '42', ['v' => 1]);
        $deleted = $this->es->purgeOlderThan('article', '42', '2025-01-01 00:00:00');
        $this->assertSame(1, $deleted);
        $this->assertCount(1, $this->es->list('article', '42'));
    }
}
