<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Enums;

/**
 * Scope of stock-item propagation for a product-type custom field.
 *
 * When the custom field value changes on a ShopWired product, this controls
 * how the change is mirrored onto its stock-system counterpart:
 *
 * - Single: update the master stock item only.
 * - AllVariants: update the master and every variation linked to it.
 *
 * The stock system is incidental to the choice itself — this is a scope
 * declaration, not a vendor coupling.
 */
enum StockItemUpdateMode: string
{
    case Single = 'single';
    case AllVariants = 'all_variants';
}
