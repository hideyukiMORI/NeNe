<?php

declare(strict_types=1);

namespace Nene\Func;

/**
 * Static utilities for offset-based pagination math.
 *
 * These are pure functions — no database calls, no state.
 */
final class PaginationHelper
{
    /**
     * Calculate the SQL OFFSET for a given page number and page size.
     *
     * @param int $page    1-based page number.
     * @param int $perPage Items per page.
     * @return int SQL OFFSET value (never negative).
     */
    public static function offset(int $page, int $perPage): int
    {
        return max(0, ($page - 1) * $perPage);
    }

    /**
     * Calculate the total number of pages.
     *
     * Returns 0 when $perPage is 0 (prevents division by zero).
     */
    public static function totalPages(int $total, int $perPage): int
    {
        if ($perPage <= 0 || $total <= 0) {
            return 0;
        }
        return (int) ceil($total / $perPage);
    }

    /**
     * Clamp a page number to the valid range [1, totalPages].
     *
     * Returns 1 if there are no pages.
     */
    public static function clamp(int $page, int $total, int $perPage): int
    {
        $pages = self::totalPages($total, $perPage);
        if ($pages === 0) {
            return 1;
        }
        return max(1, min($page, $pages));
    }

    /**
     * Generate a window of page numbers for a paginator UI.
     *
     * Returns a list of page numbers to show. Pages outside the window
     * are replaced with 0 (conventionally rendered as '…').
     *
     * Example with total=10 pages, current=5, window=2:
     *   [1, 0, 3, 4, 5, 6, 7, 0, 10]
     *
     * @param int $currentPage  Current page (1-based).
     * @param int $totalPages   Total number of pages.
     * @param int $window       How many pages to show on each side of current.
     * @return list<int>
     */
    public static function window(int $currentPage, int $totalPages, int $window = 2): array
    {
        if ($totalPages <= 0) {
            return [];
        }

        $start = max(1, $currentPage - $window);
        $end   = min($totalPages, $currentPage + $window);

        $pages = [];

        if ($start > 1) {
            $pages[] = 1;
            if ($start > 2) {
                $pages[] = 0; // ellipsis
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            $pages[] = $i;
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $pages[] = 0; // ellipsis
            }
            $pages[] = $totalPages;
        }

        return $pages;
    }
}
