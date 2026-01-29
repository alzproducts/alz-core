<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Business reason for updating a SKU.
 *
 * These values match the CHECK constraint in operations.sku_changes table.
 * Used for audit trail and analysis of SKU change patterns.
 */
enum SkuUpdateReason: string
{
    case ShortenLongSku = 'shorten_long_sku';
    case FixSkuMismatch = 'fix_sku_mismatch';
    case StandardizeFormat = 'standardize_format';
    case MergeProducts = 'merge_products';
    case Other = 'other';

    /**
     * Get human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::ShortenLongSku => 'Shorten long SKU',
            self::FixSkuMismatch => 'Fix SKU mismatch',
            self::StandardizeFormat => 'Standardize format',
            self::MergeProducts => 'Merge products',
            self::Other => 'Other',
        };
    }
}
