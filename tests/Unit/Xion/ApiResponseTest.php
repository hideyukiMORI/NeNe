<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ApiResponse;
use PHPUnit\Framework\TestCase;

final class ApiResponseTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ERROR_CODE_PATH')) {
            define('ERROR_CODE_PATH', dirname(__DIR__, 3) . '/htdocs/message/error_code.js');
        }
    }

    public function testSuccessBuildsStableResponseShape(): void
    {
        $response = (new ApiResponse())->success(['user' => ['user_id' => 'admin']]);

        self::assertSame('success', $response['status']);
        self::assertSame('', $response['errorCode']);
        self::assertSame(['user_id' => 'admin'], $response['user']);
    }

    public function testFailureBuildsStableResponseShapeFromErrorCode(): void
    {
        $response = (new ApiResponse())->failure('LOGIN-FAILED');

        self::assertSame([
            'status' => 'failure',
            'errorCode' => 'LOGIN-FAILED',
            'errorMessage' => 'Wrong user ID or user PASS'
        ], $response);
    }
}
