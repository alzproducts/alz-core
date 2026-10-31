<?php

declare(strict_types=1);

namespace App\Application\Catalog\Results;

/**
 * A rendered product list fragment, keyed by product and include shape.
 */
final readonly class ProductListFragmentDTO
{
    public function __construct(
        public string $productId,
        public string $shape,
        public string $fingerprint,
        public string $body,
    ) {}
}
