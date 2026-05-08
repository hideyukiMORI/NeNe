<?php

declare(strict_types=1);

namespace Nene\Tests\Http;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/HttpClient.php';

final class HttpErrorExposureTest extends TestCase
{
    private const ERROR_BASE_URL_ENV = 'NENE_HTTP_ERROR_BASE_URL';

    public function testProductionErrorResponseDoesNotExposeDatabaseDetails(): void
    {
        $baseUrl = (string)getenv(self::ERROR_BASE_URL_ENV);
        if ($baseUrl === '') {
            self::markTestSkipped(self::ERROR_BASE_URL_ENV . ' is not configured.');
        }

        $client = new HttpClient($baseUrl);
        $response = $client->json('POST', '/session/login', [
            'user_id' => 'admin',
            'user_pass' => 'admin',
        ]);

        self::assertSame(500, $response->statusCode());
        self::assertSame('Internal Server Error', $response->body());
        self::assertStringNotContainsString('SQLSTATE', $response->body());
        self::assertStringNotContainsString('Connection failed', $response->body());
    }
}
