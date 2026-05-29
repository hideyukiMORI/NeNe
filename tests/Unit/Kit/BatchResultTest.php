<?php

declare(strict_types=1);

namespace Nene\Tests\Unit\Kit;

use Nene\Kit\BatchResult;
use PHPUnit\Framework\TestCase;

final class BatchResultTest extends TestCase
{
    public function testEmptyBatchReturnsZeroCounts(): void
    {
        $result = new BatchResult();
        self::assertSame(0, $result->count());
        self::assertSame(0, $result->successCount());
        self::assertSame(0, $result->failureCount());
    }

    public function testAddSuccessIncreasesSuccessCount(): void
    {
        $result = new BatchResult();
        $result->addSuccess(0);
        $result->addSuccess(1);
        self::assertSame(2, $result->successCount());
        self::assertSame(2, $result->count());
        self::assertSame(0, $result->failureCount());
    }

    public function testAddSuccessWithDataPreservesData(): void
    {
        $result = new BatchResult();
        $result->addSuccess(0, ['id' => 42]);
        $items = $result->toArray()['items'];
        self::assertSame(['id' => 42], $items[0]['data']);
    }

    public function testAddSuccessWithNullDataOmitsDataKey(): void
    {
        $result = new BatchResult();
        $result->addSuccess(0, null);
        $items = $result->toArray()['items'];
        self::assertArrayNotHasKey('data', $items[0]);
    }

    public function testAddFailureIncreasesFailureCount(): void
    {
        $result = new BatchResult();
        $result->addFailure(0, 'VALIDATION-FAILED');
        $result->addFailure(1, 'VALIDATION-FAILED');
        self::assertSame(2, $result->failureCount());
        self::assertSame(2, $result->count());
        self::assertSame(0, $result->successCount());
    }

    public function testAddFailurePreservesErrorCodeAndMessage(): void
    {
        $result = new BatchResult();
        $result->addFailure(3, 'BATCH-ITEM-FAILED', 'Title is required.');
        $items = $result->toArray()['items'];
        self::assertSame('BATCH-ITEM-FAILED', $items[0]['errorCode']);
        self::assertSame('Title is required.', $items[0]['errorMessage']);
    }

    public function testMixedResultsIsPartialSuccess(): void
    {
        $result = new BatchResult();
        $result->addSuccess(0, ['id' => 1]);
        $result->addFailure(1, 'BATCH-ITEM-FAILED', 'Missing title.');
        self::assertTrue($result->isPartialSuccess());
        self::assertFalse($result->allSucceeded());
        self::assertFalse($result->allFailed());
    }

    public function testAllSucceededIsTrue(): void
    {
        $result = new BatchResult();
        $result->addSuccess(0, ['id' => 1]);
        $result->addSuccess(1, ['id' => 2]);
        self::assertTrue($result->allSucceeded());
        self::assertFalse($result->allFailed());
        self::assertFalse($result->isPartialSuccess());
    }

    public function testAllFailedIsTrue(): void
    {
        $result = new BatchResult();
        $result->addFailure(0, 'BATCH-ITEM-FAILED', 'err1');
        $result->addFailure(1, 'BATCH-ITEM-FAILED', 'err2');
        self::assertTrue($result->allFailed());
        self::assertFalse($result->allSucceeded());
        self::assertFalse($result->isPartialSuccess());
    }

    public function testHttpStatusIs200WhenAllSucceeded(): void
    {
        $result = new BatchResult();
        $result->addSuccess(0);
        $result->addSuccess(1);
        self::assertSame(200, $result->httpStatus());
    }

    public function testHttpStatusIs207WhenPartialSuccess(): void
    {
        $result = new BatchResult();
        $result->addSuccess(0, ['id' => 1]);
        $result->addFailure(1, 'BATCH-ITEM-FAILED', 'Missing title.');
        self::assertSame(207, $result->httpStatus());
    }

    public function testHttpStatusIs422WhenAllFailed(): void
    {
        $result = new BatchResult();
        $result->addFailure(0, 'BATCH-ITEM-FAILED', 'err');
        self::assertSame(422, $result->httpStatus());
    }

    public function testHttpStatusIs200WhenEmpty(): void
    {
        $result = new BatchResult();
        self::assertSame(200, $result->httpStatus());
    }

    public function testToArrayStructure(): void
    {
        $result = new BatchResult();
        $result->addSuccess(0, ['id' => 1]);
        $result->addFailure(1, 'BATCH-ITEM-FAILED', 'err');
        $arr = $result->toArray();
        self::assertArrayHasKey('items', $arr);
        self::assertArrayHasKey('succeeded', $arr);
        self::assertArrayHasKey('failed', $arr);
        self::assertSame(1, $arr['succeeded']);
        self::assertSame(1, $arr['failed']);
        self::assertCount(2, $arr['items']);
    }

    public function testIndexIsPreservedInItems(): void
    {
        $result = new BatchResult();
        $result->addSuccess(5, ['id' => 99]);
        $result->addFailure(12, 'BATCH-ITEM-FAILED', 'oops');
        $items = $result->toArray()['items'];
        self::assertSame(5, $items[0]['index']);
        self::assertSame(12, $items[1]['index']);
    }
}
