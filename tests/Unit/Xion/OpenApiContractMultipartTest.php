<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname(__DIR__, 2) . '/Http/HttpRuntimeTestCase.php';
require_once dirname(__DIR__, 2) . '/Http/OpenApiRuntimeContractTest.php';

/**
 * Pins the contract test's behaviour for `multipart/form-data` request
 * bodies. Today the test only reads JSON examples — multipart operations
 * are probed with an empty body. FT12 F-6 documents the convention; this
 * test guards it from silent regression.
 */
final class OpenApiContractMultipartTest extends TestCase
{
    public function testExtractExampleBodyReturnsNullForMultipartOnlyOperation(): void
    {
        $operation = [
            'requestBody' => [
                'content' => [
                    'multipart/form-data' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'file' => ['type' => 'string', 'format' => 'binary'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $method = new ReflectionMethod(
            'Nene\\Tests\\Http\\OpenApiRuntimeContractTest',
            'extractExampleBody'
        );
        $method->setAccessible(true);

        // Instantiate the test class without invoking its setUp; we only
        // need access to the private method shape, not a live HTTP runtime.
        $test = (new \ReflectionClass('Nene\\Tests\\Http\\OpenApiRuntimeContractTest'))
            ->newInstanceWithoutConstructor();

        $result = $method->invoke($test, $operation);
        self::assertNull(
            $result,
            'extractExampleBody must return null for multipart-only operations so the probe sends no body.'
        );
    }

    public function testExtractExampleBodyReturnsExampleForJsonOperation(): void
    {
        $operation = [
            'requestBody' => [
                'content' => [
                    'application/json' => [
                        'examples' => [
                            'default' => [
                                'value' => ['title' => 'probe-title'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $method = new ReflectionMethod(
            'Nene\\Tests\\Http\\OpenApiRuntimeContractTest',
            'extractExampleBody'
        );
        $method->setAccessible(true);
        $test = (new \ReflectionClass('Nene\\Tests\\Http\\OpenApiRuntimeContractTest'))
            ->newInstanceWithoutConstructor();

        $result = $method->invoke($test, $operation);
        self::assertSame(['title' => 'probe-title'], $result);
    }
}
