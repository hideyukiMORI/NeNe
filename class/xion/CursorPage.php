<?php

/**
 * AYANE : ayane.co.jp
 * powered by NENE.
 *
 * PHP Version >= 8.4
 *
 * @package   AYANE
 * @author    hideyukiMORI <info@ayane.co.jp>
 * @copyright 2021 AYANE
 * @license   https://opensource.org/licenses/MIT MIT License
 * @link      https://ayane.co.jp/
 */

declare(strict_types=1);

namespace Nene\Xion;

/**
 * Result of a cursor-paginated query.
 *
 * @template T
 */
final readonly class CursorPage
{
    /**
     * @param array<int,T> $items       Items for this page.
     * @param bool         $hasMore     Whether a next page exists.
     * @param string|null  $nextCursor  Opaque cursor token for the next page, or null if none.
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $hasMore,
        public readonly ?string $nextCursor,
    ) {
    }

    /**
     * Convert to an array suitable for use in an API response.
     *
     * @return array{items: array<int,T>, has_more: bool, next_cursor: string|null}
     */
    public function toArray(): array
    {
        return [
            'items'       => $this->items,
            'has_more'    => $this->hasMore,
            'next_cursor' => $this->nextCursor,
        ];
    }
}
