<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Domain\Catalog\Product\ValueObjects\Product;

/**
 * Result wrapper for GetProductUseCase.
 *
 * Carries the product and the includes list so the presentation layer
 * knows which embeds were requested (controls serialization).
 */
final readonly class GetProductResult
{
    /**
     * @param list<string> $includes Requested embed names
     */
    public function __construct(
        public Product $product,
        public array $includes,
    ) {}

    public function hasInclude(string $name): bool
    {
        return \in_array($name, $this->includes, true);
    }
}
