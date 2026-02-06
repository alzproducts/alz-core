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
     * @param bool $copyParentMpn When true, use template's default supplier code as MPN for all variants
     * @param bool $noSupplier When true, skip supplier linking in Linnworks
     * @param bool $isStandardSign When true, match cost prices against standard sign product
     */
    public function __construct(
        public IntId $productId,
        public Sku $templateSku,
        public bool $copyParentMpn = false,
        public bool $noSupplier = false,
        public bool $isStandardSign = false,
    ) {}
}
