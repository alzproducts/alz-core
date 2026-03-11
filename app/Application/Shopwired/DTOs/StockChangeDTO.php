<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

/**
 * Parsed data from a `product.stock_changed` webhook event.
 */
final readonly class StockChangeDTO
{
    public function __construct(
        public string $sku,
        public bool $isVariation,
        public int $newQuantity,
    ) {}
}
