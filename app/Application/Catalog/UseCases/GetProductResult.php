<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductView;

/**
 * Result wrapper for GetProductUseCase.
 *
 * Carries the product and the includes list so the presentation layer
 * knows which embeds were requested (controls serialization).
 */
final readonly class GetProductResult
{
    /**
     * @param list<ProductInclude> $includes Requested embeds
     */
    public function __construct(
        public ProductView $product,
        public array $includes,
    ) {}

    public function hasInclude(ProductInclude $include): bool
    {
        return \in_array($include, $this->includes, true);
    }
}
