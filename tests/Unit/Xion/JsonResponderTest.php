<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\JsonResponder;
use PHPUnit\Framework\TestCase;

final class JsonResponderTest extends TestCase
{
    public function testEnvelopeWrapsDataForRestResponses(): void
    {
        self::assertSame(
            [
                'Result' => true,
                'Data' => [
                    'status' => 'success',
                    'items' => ['one', 'two'],
                ],
            ],
            JsonResponder::envelope([
                'status' => 'success',
                'items' => ['one', 'two'],
            ])
        );
    }

    public function testErrorEnvelopeKeepsLegacyErrorShape(): void
    {
        self::assertSame(
            [
                'Result' => false,
                'Error' => [
                    'ErrorCode' => 'SAMPLE-ERROR',
                    'ErrorMessage' => 'Sample error.',
                ],
            ],
            JsonResponder::errorEnvelope('SAMPLE-ERROR', 'Sample error.')
        );
    }

    public function testFailureResponseUsesErrorCatalogHttpStatus(): void
    {
        $response = JsonResponder::responseArray([
            'status' => 'failure',
            'errorCode' => 'METHOD-NOT-ALLOWED',
            'errorMessage' => 'The HTTP method is not allowed for this endpoint.',
        ]);

        self::assertSame(405, $response->statusCode());
        self::assertSame(['Content-Type' => 'application/json; charset=utf-8'], $response->headers());
        self::assertStringContainsString('"METHOD-NOT-ALLOWED"', $response->body());
    }
}
