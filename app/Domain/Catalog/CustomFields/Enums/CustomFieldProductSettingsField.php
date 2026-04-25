<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Enums;

/**
 * Mutable columns of `catalog.custom_field_product_settings`.
 *
 * Backing values are the DB column names. All columns on this table are
 * nullable, hence every case is clearable.
 */
enum CustomFieldProductSettingsField: string
{
    case StockItemUpdateMode = 'stock_item_update_mode';

    public function isClearable(): bool
    {
        return true;
    }
}
