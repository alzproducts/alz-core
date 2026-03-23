<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

/**
 * Reason a product was automatically removed from sale.
 *
 * Used by the auto-removal cron to specify why a sale ended,
 * and threaded through to Slack notifications for visibility.
 */
enum SaleRemovalReason: string
{
    case Manual = 'manual';
    case ProductInactive = 'product_inactive';
    case EndDateReached = 'end_date_reached';
    case OutOfStockDiscontinued = 'out_of_stock_discontinued';
    case SaleUnitsSold = 'sale_units_sold';

    /**
     * Human-readable label for Slack notifications.
     */
    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual removal',
            self::ProductInactive => 'Product inactive',
            self::EndDateReached => 'Sale end date reached',
            self::OutOfStockDiscontinued => 'Out of stock (discontinued)',
            self::SaleUnitsSold => 'Sale units sold',
        };
    }
}
