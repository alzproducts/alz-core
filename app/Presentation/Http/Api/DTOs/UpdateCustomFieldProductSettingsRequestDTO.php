<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\Catalog\Commands\SaveCustomFieldProductSettingsCommand;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldProductSettingsField;
use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
use App\Domain\Catalog\CustomFields\Exceptions\ProductSettingsNotApplicableException;
use App\Presentation\Http\Api\Support\MergePatchMapper;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * PUT body for `catalog.custom_field_product_settings`.
 *
 * Partial-update semantics — absent fields left unchanged. Only valid when the
 * target definition's item_type is `product` — the Application layer rejects
 * non-product writes via {@see ProductSettingsNotApplicableException}.
 */
final class UpdateCustomFieldProductSettingsRequestDTO extends Data
{
    public function __construct(
        #[Enum(StockItemUpdateMode::class)]
        public readonly Optional|string|null $stock_item_update_mode = new Optional(),
    ) {}

    public function toCommand(): SaveCustomFieldProductSettingsCommand
    {
        [$valuesToSet, $columnsToClear] = MergePatchMapper::buildMaps([
            [CustomFieldProductSettingsField::StockItemUpdateMode, $this->stock_item_update_mode],
        ]);

        return new SaveCustomFieldProductSettingsCommand(
            valuesToSet: $valuesToSet,
            columnsToClear: $columnsToClear,
        );
    }
}
