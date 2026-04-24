<?php

declare(strict_types=1);

namespace App\Application\Catalog\Commands;

use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;

/**
 * Partial change set for `catalog.custom_field_product_settings`.
 *
 * Only columns whose name appears in {@see $touchedKeys} are written.
 */
final readonly class SaveCustomFieldProductSettingsCommand
{
    /**
     * @param list<string> $touchedKeys DB column names present in the original request payload.
     */
    public function __construct(
        public ?StockItemUpdateMode $stockItemUpdateMode,
        public array $touchedKeys,
    ) {}
}
