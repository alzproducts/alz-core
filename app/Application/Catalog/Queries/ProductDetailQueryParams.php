<?php

declare(strict_types=1);

namespace App\Application\Catalog\Queries;

use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\ValueObjects\IntId;

/**
 * Query parameters for a single product detail request.
 *
 * Encapsulates the product identifier and include options for GET /api/products/{id}.
 */
final readonly class ProductDetailQueryParams
{
    /**
     * @param list<ProductInclude> $includes Embeds to load
     */
    public function __construct(
        public IntId $productId,
        public array $includes = [],
    ) {}
}
