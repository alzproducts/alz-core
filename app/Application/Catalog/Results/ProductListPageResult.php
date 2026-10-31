<?php

declare(strict_types=1);

namespace App\Application\Catalog\Results;

/**
 * One page of the product list, read from a single snapshot.
 */
final readonly class ProductListPageResult
{
    /**
     * @param list<ProductListEntryDTO> $entries In page order
     */
    public function __construct(
        public int $total,
        public int $perPage,
        public int $currentPage,
        public string $shape,
        public array $entries,
    ) {}
}
