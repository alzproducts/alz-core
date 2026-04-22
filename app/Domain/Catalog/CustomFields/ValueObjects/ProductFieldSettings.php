<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\LinnworksStockItemUpdateMode;

/**
 * Local settings specific to product-type custom field definitions.
 *
 * Only applicable when the paired {@see CustomFieldDefinition} has
 * itemType Product; this invariant is enforced by {@see ConfiguredFieldDefinition}.
 */
final readonly class ProductFieldSettings
{
    public function __construct(
        public ?LinnworksStockItemUpdateMode $updateLinnworksStockItem,
    ) {}
}
