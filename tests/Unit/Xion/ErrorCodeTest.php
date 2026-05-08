<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ErrorCode;
use PHPUnit\Framework\TestCase;

final class ErrorCodeTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ERROR_CODE_PATH')) {
            define('ERROR_CODE_PATH', dirname(__DIR__, 3) . '/config/error_codes.php');
        }
    }

    public function testGetErrorTextReturnsCatalogMessage(): void
    {
        self::assertSame(
            'Session timeout. Please log in again.',
            ErrorCode::getInstance()->getErrorText('SESSION-CLOSED')
        );
    }

    public function testGetHttpStatusReturnsCatalogStatus(): void
    {
        self::assertSame(401, ErrorCode::getInstance()->getHttpStatus('SESSION-CLOSED'));
        self::assertSame(401, ErrorCode::getInstance()->getHttpStatus('LOGIN-FAILED'));
        self::assertSame(400, ErrorCode::getInstance()->getHttpStatus('TODO-TITLE-REQUIRED'));
        self::assertSame(404, ErrorCode::getInstance()->getHttpStatus('TODO-NOT-FOUND'));
        self::assertSame(405, ErrorCode::getInstance()->getHttpStatus('METHOD-NOT-ALLOWED'));
        self::assertSame(500, ErrorCode::getInstance()->getHttpStatus('ROUTE-CONFLICT'));
    }

    public function testGetErrorTextReturnsDeterministicFallbackForMissingCode(): void
    {
        self::assertSame(
            'Error code [UNKNOWN-CODE] is not defined.',
            ErrorCode::getInstance()->getErrorText('UNKNOWN-CODE')
        );
    }

    public function testGetHttpStatusReturnsServerErrorForMissingCode(): void
    {
        self::assertSame(500, ErrorCode::getInstance()->getHttpStatus('UNKNOWN-CODE'));
    }
}
