<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

/**
 * ShopWired custom field names used for sale state management.
 *
 * Centralizes field names to prevent typos across writers (listeners)
 * and readers (auto-removal use case).
 */
enum SaleCustomField: string
{
    case DateStart = 'sale_date_start';
    case DateEnd = 'sale_date_end';
    case Reason = 'sale_reason';
    case Comments = 'sale_comments';
    case EndsStock = 'sale_ends_stock';

    /**
     * All sale custom fields set to empty strings (for clearing on removal).
     *
     * @return array<string, string>
     */
    public static function emptyValues(): array
    {
        return \array_combine(
            \array_column(self::cases(), 'value'),
            \array_fill(0, \count(self::cases()), ''),
        );
    }
}
