<?php

declare(strict_types=1);

namespace App\Application\Catalog\Results;

use Webmozart\Assert\Assert;

/**
 * A stored list fragment paired with its product's entry rebuilt from live inputs.
 */
final readonly class ProductListFragmentSampleDTO
{
    public function __construct(
        public ProductListFragmentDTO $stored,
        public ProductListEntryDTO $rebuilt,
    ) {
        Assert::same($rebuilt->productId, $stored->productId, 'A fragment sample pairs entries of one product');
        Assert::notNull($rebuilt->view, 'A rebuilt entry carries its view');
    }
}
