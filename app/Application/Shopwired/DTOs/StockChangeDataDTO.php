<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

use App\Application\Shopwired\UseCases\Webhooks\UpdateProductStockUseCase;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;

/**
 * Typed stock change data for {@see UpdateProductStockUseCase}.
 *
 * Combines the product identity with parsed stock change fields, reducing
 * the use case's execute() signature from 7 parameters to 2 (context + data).
 */
final readonly class StockChangeDataDTO
{
    public function __construct(
        public IntId $productId,
        public Sku $sku,
        public bool $isVariation,
        public int $newQuantity,
    ) {}
}
