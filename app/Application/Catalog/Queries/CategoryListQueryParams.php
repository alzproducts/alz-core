<?php

declare(strict_types=1);

namespace App\Application\Catalog\Queries;

use App\Domain\Catalog\Category\Enums\CategoryInclude;

/**
 * Query parameters for paginated category list.
 *
 * Encapsulates includes and filter options for GET /api/categories.
 */
final readonly class CategoryListQueryParams
{
    /**
     * @param list<CategoryInclude> $includes Requested embeds
     * @param bool $includeInactive When true, includes inactive categories
     * @param bool|null $isMainCategory When true returns only main categories; false returns only non-main; null returns all
     */
    public function __construct(
        public array $includes = [],
        public bool $includeInactive = false,
        public ?bool $isMainCategory = null,
    ) {}
}
