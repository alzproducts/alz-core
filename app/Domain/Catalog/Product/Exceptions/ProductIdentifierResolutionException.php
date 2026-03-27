<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Exceptions;

use App\Domain\Exceptions\DomainException;

/**
 * Product identifier could not be resolved to a valid product.
 *
 * Thrown when attempting to resolve a SKU or product ID that doesn't exist
 * in the database. This indicates either invalid input data or a product
 * that hasn't been synced yet.
 */
final class ProductIdentifierResolutionException extends DomainException
{
    public function __construct(
        public readonly string|int $identifier,
        public readonly string $identifierType,
    ) {
        parent::__construct('Product identifier could not be resolved');
    }

    /**
     * SKU not found in products or variations.
     */
    public static function skuNotFound(string $sku): self
    {
        return new self($sku, 'sku');
    }

    /**
     * Product ID not found in database.
     */
    public static function productIdNotFound(int $productId): self
    {
        return new self($productId, 'product_id');
    }

    public function context(): array
    {
        return [
            'identifier' => $this->identifier,
            'identifier_type' => $this->identifierType,
        ];
    }
}
