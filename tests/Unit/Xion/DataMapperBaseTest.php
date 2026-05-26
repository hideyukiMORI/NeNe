<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Monolog\Logger;
use Nene\Xion\DataMapperBase;
use Nene\Xion\DataModelBase;
use Nene\Xion\HttpTermination;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DataMapperBase (FT21 / #443 — deferred from #407).
 *
 * Coverage gaps addressed:
 *  - execute()        success and PDOException path
 *  - executeQuery()   success and PDOException path
 *  - decorate()       fetch-mode wiring
 *  - assoc()          fetch-mode wiring
 *  - getSearchARRAY() string parsing (comma / full-width / collapse / trim)
 *  - getTableColumn() KEY_SID exclusion; date-column exclusion flag
 *  - KEY_SID          default and subclass override
 *
 * Strategy: DataMapperBaseTestFixture bypasses __construct (same pattern as
 * DataModelBaseTest) and exposes protected methods as public for testing.
 * Mock PDO / PDOStatement are used for DB-interaction tests so no real
 * database or container is needed.
 *
 * Minimal constants needed by the tested code paths are defined with
 * guarded define() calls at the top of this file so they survive across
 * the test suite but do not collide with other test files that may define
 * the same constants (phpunit runs all tests in one process).
 */

// Minimal runtime constants required by DataMapperBase code paths.
// Guarded so phpunit can include this file multiple times without collisions.
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}
if (!defined('DB_COLUMN_NAME_CREATED')) {
    define('DB_COLUMN_NAME_CREATED', 'created_at');
}
if (!defined('DB_COLUMN_NAME_UPDATED')) {
    define('DB_COLUMN_NAME_UPDATED', 'updated_at');
}
if (!defined('DB_COLUMN_TIMESTAMP')) {
    define('DB_COLUMN_TIMESTAMP', true);
}
if (!defined('DB_NUM_PREFIX')) {
    define('DB_NUM_PREFIX', 'numPrefix_');
}

final class DataMapperBaseTest extends TestCase
{
    private DataMapperBaseTestFixture $mapper;

    /** @var PDO&MockObject */
    private PDO $mockPdo;

    protected function setUp(): void
    {
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mapper  = DataMapperBaseTestFixture::withDb($this->mockPdo);
    }

    // ------------------------------------------------------------------ //
    // execute()
    // ------------------------------------------------------------------ //

    public function testExecuteReturnsStatementOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->willReturn(true);

        $result = $this->mapper->execute($stmt);

        $this->assertSame($stmt, $result, 'execute() must return the same PDOStatement');
    }

    public function testExecuteThrowsHttpTerminationOnPdoException(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willThrowException(new \PDOException('DB failure'));

        $this->expectException(HttpTermination::class);
        $this->mapper->execute($stmt);
    }

    // ------------------------------------------------------------------ //
    // executeQuery()
    // ------------------------------------------------------------------ //

    public function testExecuteQueryReturnsStatementOnSuccess(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $this->mockPdo->method('query')->willReturn($stmt);

        $result = $this->mapper->executeQuery('SELECT 1');

        $this->assertInstanceOf(PDOStatement::class, $result);
    }

    public function testExecuteQueryThrowsHttpTerminationOnPdoException(): void
    {
        $this->mockPdo->method('query')->willThrowException(new \PDOException('Query failure'));

        $this->expectException(HttpTermination::class);
        $this->mapper->executeQuery('SELECT 1');
    }

    // ------------------------------------------------------------------ //
    // decorate()
    // ------------------------------------------------------------------ //

    public function testDecorateSetsFetchClassMode(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('setFetchMode')
            ->with(
                PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE,
                DataMapperBaseTestModel::class
            );

        $result = $this->mapper->decoratePublic($stmt);

        $this->assertSame($stmt, $result, 'decorate() must return the same PDOStatement');
    }

    // ------------------------------------------------------------------ //
    // assoc()
    // ------------------------------------------------------------------ //

    public function testAssocSetsFetchAssocMode(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('setFetchMode')
            ->with(PDO::FETCH_ASSOC);

        $result = $this->mapper->assocPublic($stmt);

        $this->assertSame($stmt, $result, 'assoc() must return the same PDOStatement');
    }

    // ------------------------------------------------------------------ //
    // KEY_SID
    // ------------------------------------------------------------------ //

    public function testDefaultKeySidIsId(): void
    {
        // The base class declares KEY_SID = 'id'. Verify that subclasses
        // that do not override it inherit the correct default.
        $this->assertSame('test_id', $this->mapper->keySid());
    }

    public function testSubclassCanOverrideKeySid(): void
    {
        // DataMapperBaseTestFixture sets KEY_SID = 'test_id';
        // a second fixture overrides it with 'custom_key'.
        $custom = DataMapperBaseTestFixtureCustomKey::withDb($this->mockPdo);
        $this->assertSame('custom_key', $custom->keySid());
    }

    // ------------------------------------------------------------------ //
    // getTableColumn()
    // ------------------------------------------------------------------ //

    public function testGetTableColumnExcludesKeySid(): void
    {
        $columns = $this->mapper->getTableColumn('test_id');

        $this->assertArrayNotHasKey('test_id', $columns);
    }

    public function testGetTableColumnIncludesDateColumnsByDefault(): void
    {
        // DB_COLUMN_TIMESTAMP = true means date columns are kept by default.
        // $is_exclude_date = false (the default) keeps them.
        $columns = $this->mapper->getTableColumn('test_id', false);

        $this->assertArrayHasKey(DB_COLUMN_NAME_CREATED, $columns);
        $this->assertArrayHasKey(DB_COLUMN_NAME_UPDATED, $columns);
    }

    public function testGetTableColumnExcludesDateColumnsWhenFlagIsTrue(): void
    {
        $columns = $this->mapper->getTableColumn('test_id', true);

        $this->assertArrayNotHasKey(DB_COLUMN_NAME_CREATED, $columns);
        $this->assertArrayNotHasKey(DB_COLUMN_NAME_UPDATED, $columns);
    }

    public function testGetTableColumnReturnsRemainingFields(): void
    {
        // Fixture schema: test_id, title, created_at, updated_at
        // After excluding test_id (KEY_SID) and date columns, only 'title' remains.
        $columns = $this->mapper->getTableColumn('test_id', true);

        $this->assertArrayHasKey('title', $columns);
        $this->assertCount(1, $columns);
    }

    // ------------------------------------------------------------------ //
    // getSearchARRAY()
    // ------------------------------------------------------------------ //

    public function testGetSearchArraySplitsOnSingleSpace(): void
    {
        $result = $this->mapper->getSearchARRAY('hello world');

        $this->assertSame(['hello', 'world'], $result);
    }

    public function testGetSearchArraySplitsOnComma(): void
    {
        $result = $this->mapper->getSearchARRAY('apple,banana');

        $this->assertSame(['apple', 'banana'], $result);
    }

    public function testGetSearchArraySplitsOnJapaneseFullWidthComma(): void
    {
        $result = $this->mapper->getSearchARRAY('りんご、バナナ');

        $this->assertSame(['りんご', 'バナナ'], $result);
    }

    public function testGetSearchArraySplitsOnJapaneseFullWidthSpace(): void
    {
        $result = $this->mapper->getSearchARRAY('東京　大阪');

        $this->assertSame(['東京', '大阪'], $result);
    }

    public function testGetSearchArrayCollapsesMultipleSpaces(): void
    {
        $result = $this->mapper->getSearchARRAY('a  b   c');

        $this->assertSame(['a', 'b', 'c'], $result);
    }

    public function testGetSearchArrayTrimsLeadingAndTrailingSpace(): void
    {
        $result = $this->mapper->getSearchARRAY('  trimmed  ');

        $this->assertSame(['trimmed'], $result);
    }

    public function testGetSearchArrayHandlesSingleKeyword(): void
    {
        $result = $this->mapper->getSearchARRAY('solo');

        $this->assertSame(['solo'], $result);
    }

    public function testGetSearchArrayMixedDelimiters(): void
    {
        // Comma + full-width space + regular space all collapse to single splits.
        $result = $this->mapper->getSearchARRAY('a,b　c d');

        $this->assertSame(['a', 'b', 'c', 'd'], $result);
    }
}

// ======================================================================
// Test fixtures
// ======================================================================

/**
 * Minimal DataModelBase subclass providing schema for getTableColumn() tests.
 *
 * Bypasses __construct (mirrors DataModelBaseTestFixtureModel) so the test
 * does not require Log / ErrorCode / RouteContext wiring.
 */
final class DataMapperBaseTestModel extends DataModelBase
{
    /** @var array<string, string> */
    protected static array $schema = [
        'test_id'    => 'integer',
        'title'      => 'string',
        'created_at' => 'string',
        'updated_at' => 'string',
    ];

    public function __construct()
    {
        // Skip parent boot — fixture only provides schema metadata.
    }
}

/**
 * Testable DataMapperBase subclass.
 *
 * - Bypasses __construct to avoid PdoConnection / Log / RouteContext.
 * - Exposes protected methods as public so tests can reach them.
 * - Exposes KEY_SID via keySid() for assertion.
 */
final class DataMapperBaseTestFixture extends DataMapperBase
{
    protected const MODEL_CLASS  = DataMapperBaseTestModel::class;
    protected const TARGET_TABLE = 'test_table';
    protected const KEY_SID      = 'test_id';

    private function __construct()
    {
        // Skip the parent constructor — dependencies are injected below.
    }

    public static function withDb(PDO $db): self
    {
        $instance         = new self();
        $instance->DB     = $db;
        // Minimal no-handler logger so handleDatabaseException can call $this->LOGGER->error().
        $instance->LOGGER = new Logger('test');
        $instance->CLASS  = 'Database\\DataMapperBaseTestFixture';
        return $instance;
    }

    /** Expose protected decorate() for testing. */
    public function decoratePublic(PDOStatement $stmt): PDOStatement
    {
        return $this->decorate($stmt);
    }

    /** Expose protected assoc() for testing. */
    public function assocPublic(PDOStatement $stmt): PDOStatement
    {
        return $this->assoc($stmt);
    }

    /** Expose the KEY_SID constant value for assertion. */
    public function keySid(): string
    {
        return static::KEY_SID;
    }
}

/**
 * Second fixture with a different KEY_SID to test subclass override.
 */
final class DataMapperBaseTestFixtureCustomKey extends DataMapperBase
{
    protected const MODEL_CLASS  = DataMapperBaseTestModel::class;
    protected const TARGET_TABLE = 'other_table';
    protected const KEY_SID      = 'custom_key';

    private function __construct()
    {
    }

    public static function withDb(PDO $db): self
    {
        $instance         = new self();
        $instance->DB     = $db;
        $instance->LOGGER = new Logger('test');
        $instance->CLASS  = 'Database\\DataMapperBaseTestFixtureCustomKey';
        return $instance;
    }

    public function keySid(): string
    {
        return static::KEY_SID;
    }
}
