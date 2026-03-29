<?php

declare(strict_types=1);

namespace App\Application\Inventory\DTOs;

use App\Application\Inventory\Commands\GenerateVariantSkusCommand;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Inventory\ValueObjects\StockItemFull;

/**
 * Shared context for processing a batch of variations during SKU generation.
 *
 * Groups the product, template, command, and optional standard-sign reference
 * that are constant across all variations in a single generation run. The
 * individual {@see ProductVariation} is passed separately as the "iterated" item.
 */
final readonly class VariationProcessingContextDTO
{
    /**
     * @param list<ProductVariation>|null $standardSignVariations Reference variations for price matching
     */
    public function __construct(
        public Product $product,
        public StockItemFull $template,
        public GenerateVariantSkusCommand $command,
        public ?array $standardSignVariations,
    ) {}
}
