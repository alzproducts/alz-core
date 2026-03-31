<?php

declare(strict_types=1);

namespace App\Application\Catalog\Queries;

use App\Domain\Catalog\Product\Enums\ProductInclude;

/**
 * Query parameters for paginated product list.
 *
 * Encapsulates pagination and include options for GET /api/products.
 */
final readonly class ProductListQueryParams
{
    /**
     * @param list<ProductInclude> $includes Embeds to load
     */
    public function __construct(
        public int $perPage,
        public int $page,
        public array $includes = [],
    ) {}
}
