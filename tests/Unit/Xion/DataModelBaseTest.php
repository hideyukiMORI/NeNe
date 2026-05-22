<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\DataModelBase;
use PHPUnit\Framework\TestCase;

/**
 * Pin the schema-driven behavior of `DataModelBase` so the magic
 * `__get` / `__set` patterns (the five Phan baseline entries flagged
 * by the eval report at #401 § 2) cannot regress silently (#407).
 *
 * `DataModelBase::__construct` reaches for `Log`, `ErrorCode`, and the
 * route context — those are framework wiring concerns, not schema
 * concerns. The fixture model below skips that wiring so the type-cast
 * and schema-gate behavior can be tested in isolation.
 */
final class DataModelBaseTest extends TestCase
{
    public function testSetCastsBooleanValues(): void
    {
        $model = new DataModelBaseTestFixtureModel();
        $model->is_completed = '1';
        self::assertTrue($model->is_completed);
        $model->is_completed = '';
        self::assertFalse($model->is_completed);
    }

    public function testSetCastsIntegerValues(): void
    {
        $model = new DataModelBaseTestFixtureModel();
        $model->id = '42';
        self::assertSame(42, $model->id);
        $model->id = 7.9;
        self::assertSame(7, $model->id);
    }

    public function testSetCastsDoubleValues(): void
    {
        $model = new DataModelBaseTestFixtureModel();
        $model->ratio = '0.75';
        self::assertSame(0.75, $model->ratio);
    }

    public function testSetCastsStringValues(): void
    {
        $model = new DataModelBaseTestFixtureModel();
        $model->title = 123;
        self::assertSame('123', $model->title);
    }

    public function testSetSkipsCastWhenIncomingTypeMatches(): void
    {
        // Native type already matches the schema; no cast applied.
        $model = new DataModelBaseTestFixtureModel();
        $model->title = 'native';
        self::assertSame('native', $model->title);
    }

    public function testSetThrowsForUnknownProperty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SET unknown IS DISABLE');
        $model = new DataModelBaseTestFixtureModel();
        $model->unknown = 'value';
    }

    public function testGetReturnsNullWhenPropertyDeclaredButUnset(): void
    {
        $model = new DataModelBaseTestFixtureModel();
        self::assertNull($model->title);
    }

    public function testGetThrowsForUnknownProperty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GET unknown IS DISABLE');
        $model = new DataModelBaseTestFixtureModel();
        $unused = $model->unknown;
    }

    public function testIssetMatchesSetState(): void
    {
        $model = new DataModelBaseTestFixtureModel();
        self::assertFalse(isset($model->title));
        $model->title = 'set';
        self::assertTrue(isset($model->title));
    }

    public function testToArrayReturnsCurrentData(): void
    {
        $model = new DataModelBaseTestFixtureModel();
        $model->id = '7';
        $model->title = 'hello';
        $model->is_completed = '1';
        self::assertSame(
            ['id' => 7, 'title' => 'hello', 'is_completed' => true],
            $model->toArray()
        );
    }

    public function testFromArrayPopulatesData(): void
    {
        $model = new DataModelBaseTestFixtureModel();
        $model->fromArray([
            'id' => '5',
            'title' => 'imported',
            'is_completed' => 1,
        ]);
        self::assertSame(5, $model->id);
        self::assertSame('imported', $model->title);
        self::assertTrue($model->is_completed);
    }

    public function testGetSchemaReturnsConfiguredSchema(): void
    {
        $model = new DataModelBaseTestFixtureModel();
        self::assertSame(DataModelBaseTestFixtureModel::expectedSchema(), $model->getSchema());
    }
}

/**
 * Schema-only fixture model. Bypasses `DataModelBase::__construct` so
 * the test does not need a working `Log` / `ErrorCode` / `RouteContext`
 * — those are wired by `Initialize::init()` in the real request boot.
 */
final class DataModelBaseTestFixtureModel extends DataModelBase
{
    /** @var array<string,string> */
    protected static array $schema = [
        'id' => 'integer',
        'title' => 'string',
        'is_completed' => 'boolean',
        'ratio' => 'double',
    ];

    public function __construct()
    {
        // Skip the parent boot intentionally — the fixture only exercises
        // schema-driven get/set behavior, not the framework's logging
        // or error-code wiring.
    }

    /**
     * @return array<string,string>
     */
    public static function expectedSchema(): array
    {
        return self::$schema;
    }
}
