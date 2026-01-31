<?php

declare(strict_types=1);

namespace App\Application\Inventory\Commands;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;

/**
 * Command to generate Linnworks inventory items for SKU-less product variations.
 *
 * @template-pattern Application Command
 */
final readonly class GenerateVariantSkusCommand
{
    /**
     * @param IntId $productId ShopWired product external ID
     * @param Sku $templateSku Linnworks SKU to use as template for category/supplier
     */
    public function __construct(
        public IntId $productId,
        public Sku $templateSku,
    ) {}
}
