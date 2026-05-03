<?php

declare(strict_types=1);

namespace App\Application\Catalog\Queries;

use App\Domain\Catalog\Product\Enums\VariationFilterField;
use App\Domain\Catalog\Product\Enums\VariationInclude;
use App\Domain\Catalog\Product\Enums\VariationSortField;
use App\Domain\Shared\Pagination\Enums\SortDirection;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;

/**
 * Query parameters for paginated variation list.
 *
 * Encapsulates pagination, includes, sort, and filter options for GET /api/variations.
 */
final readonly class VariationListQueryParams
{
    /**
     * @param list<VariationInclude> $includes Embeds to load
     * @param array<value-of<VariationFilterField>, mixed> $filters Column filters keyed by VariationFilterField value
     */
    public function __construct(
        public PageRequest $pagination,
        public array $includes = [],
        public ?VariationSortField $sortField = null,
        public SortDirection $sortDirection = SortDirection::Asc,
        public array $filters = [],
    ) {}

    public function hasInclude(VariationInclude $include): bool
    {
        return \in_array($include, $this->includes, true);
    }
}
