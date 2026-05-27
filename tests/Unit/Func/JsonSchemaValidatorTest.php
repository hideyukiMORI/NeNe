<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Func;

use Nene\Func\JsonSchemaValidator;
use PHPUnit\Framework\TestCase;

final class JsonSchemaValidatorTest extends TestCase
{
    // --- no schema keywords → always valid ---

    public function testEmptySchemaAlwaysPasses(): void
    {
        self::assertSame([], JsonSchemaValidator::validate('anything', []));
        self::assertSame([], JsonSchemaValidator::validate(42, []));
        self::assertSame([], JsonSchemaValidator::validate(null, []));
    }

    // --- type: string ---

    public function testTypeStringPassesForString(): void
    {
        self::assertSame([], JsonSchemaValidator::validate('hello', ['type' => 'string']));
    }

    public function testTypeStringFailsForInteger(): void
    {
        $errors = JsonSchemaValidator::validate(42, ['type' => 'string']);
        self::assertCount(1, $errors);
        self::assertStringContainsString('string', $errors[0]);
    }

    // --- type: integer ---

    public function testTypeIntegerPassesForInt(): void
    {
        self::assertSame([], JsonSchemaValidator::validate(0, ['type' => 'integer']));
    }

    public function testTypeIntegerFailsForFloat(): void
    {
        $errors = JsonSchemaValidator::validate(1.5, ['type' => 'integer']);
        self::assertCount(1, $errors);
    }

    // --- type: number ---

    public function testTypeNumberPassesForIntAndFloat(): void
    {
        self::assertSame([], JsonSchemaValidator::validate(3, ['type' => 'number']));
        self::assertSame([], JsonSchemaValidator::validate(3.14, ['type' => 'number']));
    }

    public function testTypeNumberFailsForString(): void
    {
        self::assertNotSame([], JsonSchemaValidator::validate('3', ['type' => 'number']));
    }

    // --- type: boolean ---

    public function testTypeBooleanPassesForBool(): void
    {
        self::assertSame([], JsonSchemaValidator::validate(true, ['type' => 'boolean']));
        self::assertSame([], JsonSchemaValidator::validate(false, ['type' => 'boolean']));
    }

    public function testTypeBooleanFailsForInteger(): void
    {
        self::assertNotSame([], JsonSchemaValidator::validate(1, ['type' => 'boolean']));
    }

    // --- type: null ---

    public function testTypeNullPassesForNull(): void
    {
        self::assertSame([], JsonSchemaValidator::validate(null, ['type' => 'null']));
    }

    public function testTypeNullFailsForString(): void
    {
        self::assertNotSame([], JsonSchemaValidator::validate('', ['type' => 'null']));
    }

    // --- nullable ---

    public function testNullableAllowsNull(): void
    {
        $schema = ['type' => 'string', 'nullable' => true];
        self::assertSame([], JsonSchemaValidator::validate(null, $schema));
    }

    public function testNullableStringStillValidatesWhenNotNull(): void
    {
        $schema = ['type' => 'string', 'nullable' => true];
        self::assertSame([], JsonSchemaValidator::validate('hello', $schema));
        self::assertNotSame([], JsonSchemaValidator::validate(42, $schema));
    }

    public function testNonNullableFailsForNull(): void
    {
        $errors = JsonSchemaValidator::validate(null, ['type' => 'string']);
        self::assertNotSame([], $errors);
    }

    // --- minLength / maxLength ---

    public function testMinLengthPass(): void
    {
        self::assertSame([], JsonSchemaValidator::validate('abc', ['minLength' => 3]));
    }

    public function testMinLengthFail(): void
    {
        $errors = JsonSchemaValidator::validate('ab', ['minLength' => 3]);
        self::assertCount(1, $errors);
        self::assertStringContainsString('3', $errors[0]);
    }

    public function testMaxLengthPass(): void
    {
        self::assertSame([], JsonSchemaValidator::validate('hi', ['maxLength' => 5]));
    }

    public function testMaxLengthFail(): void
    {
        $errors = JsonSchemaValidator::validate('toolong', ['maxLength' => 5]);
        self::assertCount(1, $errors);
        self::assertStringContainsString('5', $errors[0]);
    }

    public function testMinAndMaxLengthBothApply(): void
    {
        $schema = ['minLength' => 2, 'maxLength' => 4];
        self::assertSame([], JsonSchemaValidator::validate('abc', $schema));
        self::assertCount(1, JsonSchemaValidator::validate('a', $schema));
        self::assertCount(1, JsonSchemaValidator::validate('abcde', $schema));
    }

    // --- pattern ---

    public function testPatternPass(): void
    {
        self::assertSame(
            [],
            JsonSchemaValidator::validate('hello123', ['pattern' => '/^[a-z0-9]+$/'])
        );
    }

    public function testPatternFail(): void
    {
        $errors = JsonSchemaValidator::validate('Hello!', ['pattern' => '/^[a-z0-9]+$/']);
        self::assertCount(1, $errors);
    }

    // --- minimum / maximum ---

    public function testMinimumPass(): void
    {
        self::assertSame([], JsonSchemaValidator::validate(5, ['minimum' => 5]));
        self::assertSame([], JsonSchemaValidator::validate(6, ['minimum' => 5]));
    }

    public function testMinimumFail(): void
    {
        $errors = JsonSchemaValidator::validate(4, ['minimum' => 5]);
        self::assertCount(1, $errors);
    }

    public function testMaximumPass(): void
    {
        self::assertSame([], JsonSchemaValidator::validate(10, ['maximum' => 10]));
    }

    public function testMaximumFail(): void
    {
        $errors = JsonSchemaValidator::validate(11, ['maximum' => 10]);
        self::assertCount(1, $errors);
    }

    // --- enum ---

    public function testEnumPass(): void
    {
        $schema = ['enum' => ['admin', 'user', 'guest']];
        self::assertSame([], JsonSchemaValidator::validate('admin', $schema));
    }

    public function testEnumFail(): void
    {
        $schema = ['enum' => ['admin', 'user', 'guest']];
        $errors = JsonSchemaValidator::validate('root', $schema);
        self::assertCount(1, $errors);
        self::assertStringContainsString('admin', $errors[0]);
    }

    public function testEnumStrictComparison(): void
    {
        // '1' is not the same as 1 (strict)
        $errors = JsonSchemaValidator::validate('1', ['enum' => [1, 2, 3]]);
        self::assertCount(1, $errors);
    }

    // --- required ---

    public function testRequiredPass(): void
    {
        $schema = ['required' => ['name', 'age']];
        self::assertSame([], JsonSchemaValidator::validate(['name' => 'x', 'age' => 1], $schema));
    }

    public function testRequiredFail(): void
    {
        $schema = ['required' => ['name', 'email']];
        $errors = JsonSchemaValidator::validate(['name' => 'x'], $schema);
        self::assertCount(1, $errors);
        self::assertStringContainsString('email', $errors[0]);
        self::assertStringContainsString('required', $errors[0]);
    }

    public function testMultipleRequiredFieldsMissing(): void
    {
        $schema = ['required' => ['a', 'b', 'c']];
        $errors = JsonSchemaValidator::validate([], $schema);
        self::assertCount(3, $errors);
    }

    // --- properties ---

    public function testPropertiesValidateNestedFields(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'age'  => ['type' => 'integer'],
            ],
        ];
        self::assertSame([], JsonSchemaValidator::validate(['name' => 'Taro', 'age' => 30], $schema));
    }

    public function testPropertiesErrorIncludesPath(): void
    {
        $schema = [
            'properties' => [
                'age' => ['type' => 'integer'],
            ],
        ];
        $errors = JsonSchemaValidator::validate(['age' => 'thirty'], $schema);
        self::assertCount(1, $errors);
        self::assertStringContainsString('/age', $errors[0]);
    }

    public function testPropertiesSkipsAbsentOptionalFields(): void
    {
        $schema = [
            'properties' => [
                'optional' => ['type' => 'string'],
            ],
        ];
        // 'optional' key is absent — no error
        self::assertSame([], JsonSchemaValidator::validate([], $schema));
    }

    // --- items ---

    public function testItemsPassForAllMatchingElements(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'integer']];
        self::assertSame([], JsonSchemaValidator::validate([1, 2, 3], $schema));
    }

    public function testItemsErrorIncludesIndex(): void
    {
        $schema = ['items' => ['type' => 'integer']];
        $errors = JsonSchemaValidator::validate([1, 'bad', 3], $schema);
        self::assertCount(1, $errors);
        self::assertStringContainsString('/1', $errors[0]);
    }

    public function testItemsMultipleErrors(): void
    {
        $schema = ['items' => ['type' => 'integer']];
        $errors = JsonSchemaValidator::validate(['a', 'b'], $schema);
        self::assertCount(2, $errors);
    }

    // --- nested object ---

    public function testDeepNestedValidation(): void
    {
        $schema = [
            'type'       => 'object',
            'required'   => ['address'],
            'properties' => [
                'address' => [
                    'type'       => 'object',
                    'required'   => ['city'],
                    'properties' => [
                        'city'    => ['type' => 'string'],
                        'zipcode' => ['type' => 'string', 'pattern' => '/^\d{3}-\d{4}$/'],
                    ],
                ],
            ],
        ];

        // Valid
        $valid = ['address' => ['city' => 'Tokyo', 'zipcode' => '100-0001']];
        self::assertSame([], JsonSchemaValidator::validate($valid, $schema));

        // Missing nested required
        $missingCity = ['address' => ['zipcode' => '100-0001']];
        $errors      = JsonSchemaValidator::validate($missingCity, $schema);
        self::assertCount(1, $errors);
        self::assertStringContainsString('/address/city', $errors[0]);

        // Wrong type in nested
        $wrongType = ['address' => ['city' => 123]];
        $errors    = JsonSchemaValidator::validate($wrongType, $schema);
        self::assertCount(1, $errors); // city missing (required) not caught, but type wrong
    }

    // --- array of objects ---

    public function testArrayOfObjects(): void
    {
        $schema = [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'required'   => ['id', 'name'],
                'properties' => [
                    'id'   => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                ],
            ],
        ];

        $valid = [
            ['id' => 1, 'name' => 'first'],
            ['id' => 2, 'name' => 'second'],
        ];
        self::assertSame([], JsonSchemaValidator::validate($valid, $schema));

        $invalid = [
            ['id' => 1, 'name' => 'ok'],
            ['id' => 'bad'],           // id is wrong type, name missing
        ];
        $errors = JsonSchemaValidator::validate($invalid, $schema);
        self::assertCount(2, $errors); // type error for id + required name
        self::assertStringContainsString('/1/', $errors[0]);
    }

    // --- JSON pointer path ---

    public function testRootPointerLabel(): void
    {
        $errors = JsonSchemaValidator::validate(42, ['type' => 'string']);
        self::assertStringContainsString('/', $errors[0]);
    }

    public function testNestedPointerPath(): void
    {
        $schema = ['properties' => ['a' => ['properties' => ['b' => ['type' => 'string']]]]];
        $errors = JsonSchemaValidator::validate(['a' => ['b' => 99]], $schema);
        self::assertCount(1, $errors);
        self::assertStringContainsString('/a/b', $errors[0]);
    }
}
