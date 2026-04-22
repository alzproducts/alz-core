<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

/**
 * Framework-free paginated result.
 *
 * Decouples Application layer from Illuminate\Pagination (Deptrac boundary).
 * Reconstructed as LengthAwarePaginator in Presentation for Resource compatibility.
 *
 * @template-covariant T
 */
final readonly class PaginatedList
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
    ) {}

    /**
     * Create from paginator values, computing lastPage automatically.
     *
     * Preferred over the constructor when lastPage is derivable from total/perPage.
     *
     * @template TItem
     *
     * @param list<TItem> $items
     *
     * @return self<TItem>
     */
    public static function fromPage(array $items, int $total, int $perPage, int $currentPage): self
    {
        return new self(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $currentPage,
            lastPage: (int) \ceil($total / \max(1, $perPage)),
        );
    }
}
