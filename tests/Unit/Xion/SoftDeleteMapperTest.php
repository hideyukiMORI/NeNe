<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Monolog\Logger;
use Nene\Xion\DataMapperBase;
use Nene\Xion\DataModelBase;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DataMapperBase soft-delete feature (FT26).
 *
 * Coverage:
 *  - SOFT_DELETE = false: delete() performs physical DELETE (DB_IS_PHYSICAL_DELETE=true)
 *  - SOFT_DELETE = true:  delete() calls softDelete() → UPDATE SET deleted_at = NOW()
 *  - restore()            UPDATE SET deleted_at = NULL, returns bool from rowCount
 *  - purge()              DELETE WHERE deleted_at IS NOT NULL, returns bool from rowCount
 *  - purgeAll()           DELETE WHERE deleted_at IS NOT NULL, returns int rowCount
 *  - findTrashed()        SELECT WHERE deleted_at IS NOT NULL
 *  - find()       with SOFT_DELETE=true includes AND deleted_at IS NULL
 *  - findALL()    with SOFT_DELETE=true includes AND deleted_at IS NULL
 *  - countAll()   with SOFT_DELETE=true includes AND deleted_at IS NULL
 *  - softDelete() with SOFT_DELETE=false is a no-op
 *  - restore()    with SOFT_DELETE=false returns false
 *  - purge()      with SOFT_DELETE=false returns false
 *  - purgeAll()   with SOFT_DELETE=false returns 0
 *
 * Strategy: Mock PDO / PDOStatement — no real DB or container needed.
 * Constants guarded with if (!defined()) to survive multi-test-file runs.
 */

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}
if (!defined('DB_COLUMN_NAME_CREATED')) {
    define('DB_COLUMN_NAME_CREATED', 'created_at');
}
if (!defined('DB_COLUMN_NAME_UPDATED')) {
    define('DB_COLUMN_NAME_UPDATED', 'updated_at');
}
if (!defined('DB_COLUMN_NAME_DELETED')) {
    define('DB_COLUMN_NAME_DELETED', 'deleted_at');
}
if (!defined('DB_COLUMN_TIMESTAMP')) {
    define('DB_COLUMN_TIMESTAMP', true);
}
if (!defined('DB_NUM_PREFIX')) {
    define('DB_NUM_PREFIX', 'numPrefix_');
}
if (!defined('DB_IS_PHYSICAL_DELETE')) {
    define('DB_IS_PHYSICAL_DELETE', true);
}

final class SoftDeleteMapperTest extends TestCase
{
    /** @var PDO&MockObject */
    private PDO $mockPdo;

    protected function setUp(): void
    {
        $this->mockPdo = $this->createMock(PDO::class);
    }

    // ------------------------------------------------------------------ //
    // Helper — build a minimal model stub
    // ------------------------------------------------------------------ //

    private function makeModel(int $id = 1): SoftDeleteTestModel
    {
        $m = new SoftDeleteTestModel();
        $m->id = $id;
        return $m;
    }

    // ------------------------------------------------------------------ //
    // delete() — SOFT_DELETE = false, DB_IS_PHYSICAL_DELETE = true
    // ------------------------------------------------------------------ //

    public function testDeleteWithSoftDeleteFalsePerformsPhysicalDelete(): void
    {
        $mapper = SoftDeleteOffMapper::withDb($this->mockPdo);
        $model  = $this->makeModel(42);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('bindParam')->willReturn(true);

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('DELETE FROM'))
            ->willReturn($stmt);

        $mapper->delete($model);
    }

    // ------------------------------------------------------------------ //
    // delete() — SOFT_DELETE = true → calls softDelete() (UPDATE)
    // ------------------------------------------------------------------ //

    public function testDeleteWithSoftDeleteTrueCallsSoftDelete(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);
        $model  = $this->makeModel(7);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('bindParam')->willReturn(true);

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('SET deleted_at'))
            ->willReturn($stmt);

        $mapper->delete($model);
    }

    // ------------------------------------------------------------------ //
    // softDelete() — SOFT_DELETE = false → no-op
    // ------------------------------------------------------------------ //

    public function testSoftDeleteWithSoftDeleteFalseIsNoop(): void
    {
        $mapper = SoftDeleteOffMapper::withDb($this->mockPdo);
        $model  = $this->makeModel(1);

        // prepare() must NOT be called
        $this->mockPdo->expects($this->never())->method('prepare');

        $mapper->softDelete($model);
    }

    // ------------------------------------------------------------------ //
    // restore()
    // ------------------------------------------------------------------ //

    public function testRestoreExecutesUpdateAndReturnsTrueWhenRowFound(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('bindParam')->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('deleted_at = NULL'))
            ->willReturn($stmt);

        $result = $mapper->restore(5);

        $this->assertTrue($result);
    }

    public function testRestoreReturnsFalseWhenRowNotInTrash(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('bindParam')->willReturn(true);
        $stmt->method('rowCount')->willReturn(0);

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($stmt);

        $result = $mapper->restore(99);

        $this->assertFalse($result);
    }

    public function testRestoreWithSoftDeleteFalseReturnsFalse(): void
    {
        $mapper = SoftDeleteOffMapper::withDb($this->mockPdo);

        $this->mockPdo->expects($this->never())->method('prepare');

        $result = $mapper->restore(1);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------ //
    // purge()
    // ------------------------------------------------------------------ //

    public function testPurgeDeletesRowInTrashAndReturnsTrue(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('bindParam')->willReturn(true);
        $stmt->method('rowCount')->willReturn(1);

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('DELETE FROM'),
                $this->stringContains('deleted_at IS NOT NULL')
            ))
            ->willReturn($stmt);

        $result = $mapper->purge(3);

        $this->assertTrue($result);
    }

    public function testPurgeReturnsFalseWhenRowNotInTrash(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('bindParam')->willReturn(true);
        $stmt->method('rowCount')->willReturn(0);

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->willReturn($stmt);

        $result = $mapper->purge(99);

        $this->assertFalse($result);
    }

    public function testPurgeWithSoftDeleteFalseReturnsFalse(): void
    {
        $mapper = SoftDeleteOffMapper::withDb($this->mockPdo);

        $this->mockPdo->expects($this->never())->method('prepare');

        $result = $mapper->purge(1);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------ //
    // purgeAll()
    // ------------------------------------------------------------------ //

    public function testPurgeAllDeletesAllTrashedRowsAndReturnsCount(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('rowCount')->willReturn(4);

        $this->mockPdo->expects($this->once())
            ->method('query')
            ->with($this->logicalAnd(
                $this->stringContains('DELETE FROM'),
                $this->stringContains('deleted_at IS NOT NULL')
            ))
            ->willReturn($stmt);

        $count = $mapper->purgeAll();

        $this->assertSame(4, $count);
    }

    public function testPurgeAllWithSoftDeleteFalseReturnsZero(): void
    {
        $mapper = SoftDeleteOffMapper::withDb($this->mockPdo);

        $this->mockPdo->expects($this->never())->method('query');

        $count = $mapper->purgeAll();

        $this->assertSame(0, $count);
    }

    // ------------------------------------------------------------------ //
    // findTrashed()
    // ------------------------------------------------------------------ //

    public function testFindTrashedQueryIncludesDeletedAtIsNotNull(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('setFetchMode')->willReturn(true);

        $this->mockPdo->expects($this->once())
            ->method('query')
            ->with($this->stringContains('deleted_at IS NOT NULL'))
            ->willReturn($stmt);

        $result = $mapper->findTrashed();

        $this->assertInstanceOf(PDOStatement::class, $result);
    }

    // ------------------------------------------------------------------ //
    // find() — SOFT_DELETE = true includes AND deleted_at IS NULL
    // ------------------------------------------------------------------ //

    public function testFindWithSoftDeleteTrueIncludesDeletedAtFilter(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('bindParam')->willReturn(true);
        $stmt->method('setFetchMode')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('deleted_at IS NULL'))
            ->willReturn($stmt);

        $mapper->find(1);
    }

    public function testFindWithSoftDeleteFalseOmitsDeletedAtFilter(): void
    {
        $mapper = SoftDeleteOffMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->method('bindParam')->willReturn(true);
        $stmt->method('setFetchMode')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalNot($this->stringContains('deleted_at')))
            ->willReturn($stmt);

        $mapper->find(1);
    }

    // ------------------------------------------------------------------ //
    // findALL() — SOFT_DELETE = true includes AND deleted_at IS NULL
    // ------------------------------------------------------------------ //

    public function testFindAllWithSoftDeleteTrueIncludesDeletedAtFilter(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('setFetchMode')->willReturn(true);

        $this->mockPdo->expects($this->once())
            ->method('query')
            ->with($this->stringContains('deleted_at IS NULL'))
            ->willReturn($stmt);

        $mapper->findALL();
    }

    public function testFindAllWithSoftDeleteFalseOmitsDeletedAtFilter(): void
    {
        $mapper = SoftDeleteOffMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('setFetchMode')->willReturn(true);

        $this->mockPdo->expects($this->once())
            ->method('query')
            ->with($this->logicalNot($this->stringContains('deleted_at')))
            ->willReturn($stmt);

        $mapper->findALL();
    }

    // ------------------------------------------------------------------ //
    // countAll() — SOFT_DELETE = true includes AND deleted_at IS NULL
    // ------------------------------------------------------------------ //

    public function testCountAllWithSoftDeleteTrueIncludesDeletedAtFilter(): void
    {
        $mapper = SoftDeleteOnMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn('3');

        $this->mockPdo->expects($this->once())
            ->method('query')
            ->with($this->stringContains('deleted_at IS NULL'))
            ->willReturn($stmt);

        $count = $mapper->countAll();

        $this->assertSame(3, $count);
    }

    public function testCountAllWithSoftDeleteFalseOmitsDeletedAtFilter(): void
    {
        $mapper = SoftDeleteOffMapper::withDb($this->mockPdo);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn('5');

        $this->mockPdo->expects($this->once())
            ->method('query')
            ->with($this->logicalNot($this->stringContains('deleted_at')))
            ->willReturn($stmt);

        $count = $mapper->countAll();

        $this->assertSame(5, $count);
    }
}

// ======================================================================
// Test fixtures
// ======================================================================

/**
 * Minimal DataModelBase subclass for soft-delete tests.
 */
final class SoftDeleteTestModel extends DataModelBase
{
    /** @var array<string, string> */
    protected static array $schema = [
        'id'         => 'integer',
        'title'      => 'string',
        'deleted_at' => 'string',
        'created_at' => 'string',
        'updated_at' => 'string',
    ];

    public int $id    = 0;
    public string $title = '';
    public ?string $deleted_at = null;

    public function __construct()
    {
        // Skip parent boot — fixture only.
    }
}

/**
 * Mapper fixture with SOFT_DELETE = false (default behaviour).
 */
final class SoftDeleteOffMapper extends DataMapperBase
{
    protected const MODEL_CLASS  = SoftDeleteTestModel::class;
    protected const TARGET_TABLE = 'soft_items';
    protected const KEY_SID      = 'id';
    protected const SOFT_DELETE  = false;

    private function __construct()
    {
    }

    public static function withDb(PDO $db): self
    {
        $instance         = new self();
        $instance->DB     = $db;
        $instance->LOGGER = new Logger('test');
        $instance->CLASS  = 'Database\\SoftDeleteOffMapper';
        return $instance;
    }
}

/**
 * Mapper fixture with SOFT_DELETE = true (opt-in soft delete).
 */
final class SoftDeleteOnMapper extends DataMapperBase
{
    protected const MODEL_CLASS  = SoftDeleteTestModel::class;
    protected const TARGET_TABLE = 'soft_items';
    protected const KEY_SID      = 'id';
    protected const SOFT_DELETE  = true;

    private function __construct()
    {
    }

    public static function withDb(PDO $db): self
    {
        $instance         = new self();
        $instance->DB     = $db;
        $instance->LOGGER = new Logger('test');
        $instance->CLASS  = 'Database\\SoftDeleteOnMapper';
        return $instance;
    }
}
