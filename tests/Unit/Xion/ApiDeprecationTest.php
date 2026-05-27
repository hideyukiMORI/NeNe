<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Xion;

use Nene\Xion\ApiDeprecation;
use PHPUnit\Framework\TestCase;

final class ApiDeprecationTest extends TestCase
{
    public function testSendHeadersWithDateOnlyDoesNotThrow(): void
    {
        // header() is a no-op in CLI, but must not throw
        ApiDeprecation::sendHeaders('2027-01-01');
        $this->addToAssertionCount(1);
    }

    public function testSendHeadersWithSuccessorDoesNotThrow(): void
    {
        ApiDeprecation::sendHeaders('2027-01-01', '/v2/notes');
        $this->addToAssertionCount(1);
    }

    public function testSendHeadersWithRfc7231DateDoesNotThrow(): void
    {
        ApiDeprecation::sendHeaders('Mon, 01 Jan 2027 23:59:59 GMT');
        $this->addToAssertionCount(1);
    }
}
