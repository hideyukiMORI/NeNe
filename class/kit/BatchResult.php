<?php

declare(strict_types=1);

namespace Nene\Kit;

/**
 * Accumulates per-item results for a batch operation.
 *
 * Usage:
 *   $result = new BatchResult();
 *   foreach ($items as $i => $item) {
 *       try {
 *           $id = $mapper->create($item);
 *           $result->addSuccess($i, ['id' => $id]);
 *       } catch (\Throwable $e) {
 *           $result->addFailure($i, 'VALIDATION-FAILED', $e->getMessage());
 *       }
 *   }
 *   http_response_code($result->httpStatus());
 *   echo json_encode($result->toArray());
 */
final class BatchResult
{
    /** @var array<int, array{index: int, status: 'success'|'failure', data?: mixed, errorCode?: string, errorMessage?: string}> */
    private array $items = [];

    /**
     * Record a successful item.
     *
     * @param int   $index Zero-based position in the original request array.
     * @param mixed $data  The data to return for this item (optional).
     */
    public function addSuccess(int $index, mixed $data = null): void
    {
        $entry = ['index' => $index, 'status' => 'success'];
        if ($data !== null) {
            $entry['data'] = $data;
        }
        $this->items[] = $entry;
    }

    /**
     * Record a failed item.
     *
     * @param int    $index        Zero-based position in the original request array.
     * @param string $errorCode    Machine-readable error code.
     * @param string $errorMessage Human-readable description.
     */
    public function addFailure(int $index, string $errorCode, string $errorMessage = ''): void
    {
        $this->items[] = [
            'index'        => $index,
            'status'       => 'failure',
            'errorCode'    => $errorCode,
            'errorMessage' => $errorMessage,
        ];
    }

    /**
     * Total number of items recorded.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Number of successful items.
     */
    public function successCount(): int
    {
        return count(array_filter($this->items, fn ($i) => $i['status'] === 'success'));
    }

    /**
     * Number of failed items.
     */
    public function failureCount(): int
    {
        return count(array_filter($this->items, fn ($i) => $i['status'] === 'failure'));
    }

    /**
     * True if at least one item succeeded and at least one failed.
     */
    public function isPartialSuccess(): bool
    {
        return $this->successCount() > 0 && $this->failureCount() > 0;
    }

    /**
     * True if every recorded item succeeded.
     */
    public function allSucceeded(): bool
    {
        return $this->count() > 0 && $this->failureCount() === 0;
    }

    /**
     * True if every recorded item failed.
     */
    public function allFailed(): bool
    {
        return $this->count() > 0 && $this->successCount() === 0;
    }

    /**
     * Suggested HTTP status code for the response.
     *
     * - 200  all succeeded (or empty)
     * - 207  partial success
     * - 422  all failed
     */
    public function httpStatus(): int
    {
        if ($this->isPartialSuccess()) {
            return 207;
        }
        if ($this->allFailed()) {
            return 422;
        }
        return 200;
    }

    /**
     * Serialize to an array suitable for JSON encoding.
     *
     * @return array{items: list<array<string,mixed>>, succeeded: int, failed: int}
     */
    public function toArray(): array
    {
        return [
            'items'     => array_values($this->items),
            'succeeded' => $this->successCount(),
            'failed'    => $this->failureCount(),
        ];
    }
}
