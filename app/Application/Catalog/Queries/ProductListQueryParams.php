<?php

declare(strict_types=1);

namespace App\Application\Catalog\Queries;

use App\Domain\Catalog\Product\Enums\ProductFilterField;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\Enums\ProductSortField;
use App\Domain\Shared\Pagination\Enums\SortDirection;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;

/**
 * Query parameters for paginated product list.
 *
 * Encapsulates pagination, includes, sort, and filter options for GET /api/products.
 */
final readonly class ProductListQueryParams
{
    /**
     * @param list<ProductInclude> $includes Embeds to load
     * @param array<value-of<ProductFilterField>, mixed> $filters Column filters keyed by ProductFilterField value
     */
    public function __construct(
        public PageRequest $pagination,
        public array $includes = [],
        public ?ProductSortField $sortField = null,
        public SortDirection $sortDirection = SortDirection::Asc,
        public array $filters = [],
    ) {}

    /**
     * Construct a query for active products sorted by title (the standard API default).
     *
     * @param list<ProductInclude> $includes
     */
    public static function active(PageRequest $pagination, array $includes = []): self
    {
        return new self(
            pagination: $pagination,
            includes: $includes,
            sortField: ProductSortField::Title,
            sortDirection: SortDirection::Asc,
            filters: [ProductFilterField::IsActive->value => true],
        );
    }

    public function hasInclude(ProductInclude $include): bool
    {
        return \in_array($include, $this->includes, true);
    }
}
