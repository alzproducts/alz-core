<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Exceptions;

use App\Domain\Exceptions\DomainException;
use Override;

/**
 * A stored products-list fragment matches its product's live fingerprint but not the body rebuilt
 * from those inputs: something the body is built from does not reach the fingerprint, so readers
 * would keep serving a stale body.
 *
 * Resolution: find the builder input missing from the list statement's fingerprinted inputs and add it.
 */
final class ProductListFragmentDriftException extends DomainException
{
    /**
     * @param string $productId shopwired.products uuid
     * @param string $shape canonical include set the fragment was rendered for
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $shape,
    ) {
        parent::__construct('Product list fragment drifted from its rebuilt body');
    }

    #[Override]
    public function context(): array
    {
        return [
            'product_id' => $this->productId,
            'shape' => $this->shape,
        ];
    }
}
