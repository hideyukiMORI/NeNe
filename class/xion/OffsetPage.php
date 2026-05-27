<?php
declare(strict_types=1);
namespace Nene\Xion;

/**
 * Paginated result set with offset-based (page number) navigation metadata.
 *
 * Complements CursorPage (FT25) for use-cases that need page numbers:
 * admin tables, search results, sitemap generation.
 *
 * Usage in a DataMapper subclass:
 *
 *   public function paginateOffset(int $page = 1, int $perPage = 20): OffsetPage
 *   {
 *       $page    = max(1, $page);
 *       $perPage = max(1, min(100, $perPage));
 *       $total   = $this->countAll();
 *       $offset  = ($page - 1) * $perPage;
 *       $items   = $this->db->query(
 *           "SELECT * FROM {$this->TABLE} LIMIT {$perPage} OFFSET {$offset}"
 *       )->fetchAll(PDO::FETCH_ASSOC);
 *       return new OffsetPage($items, $total, $page, $perPage);
 *   }
 */
final readonly class OffsetPage
{
    public readonly int $totalPages;
    public readonly bool $hasPrev;
    public readonly bool $hasNext;

    /**
     * @param list<mixed> $items   The items for the current page.
     * @param int         $total   Total number of items across all pages.
     * @param int         $page    Current page number (1-based).
     * @param int         $perPage Items per page.
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
        $this->totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;
        $this->hasPrev    = $page > 1;
        $this->hasNext    = $page < $this->totalPages;
    }

    /**
     * Serialize to an array suitable for JSON encoding.
     *
     * @return array{items: list<mixed>, pagination: array{page: int, per_page: int, total: int, total_pages: int, has_prev: bool, has_next: bool}}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'pagination' => [
                'page'        => $this->page,
                'per_page'    => $this->perPage,
                'total'       => $this->total,
                'total_pages' => $this->totalPages,
                'has_prev'    => $this->hasPrev,
                'has_next'    => $this->hasNext,
            ],
        ];
    }
}
