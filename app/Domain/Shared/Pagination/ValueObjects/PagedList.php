<?php

declare(strict_types=1);

namespace App\Domain\Shared\Pagination\ValueObjects;

/**
 * Immutable value object representing a page of results with pagination metadata.
 *
 * Framework-free — decouples Domain and Application from Illuminate\Pagination.
 * Reconstructed as LengthAwarePaginator in Presentation for Resource compatibility.
 *
 * @template-covariant T
 */
final readonly class PagedList
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
