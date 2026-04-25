<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\CustomFields\Models;

use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Shopwired\Models\CustomFieldDefinitionModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Eloquent model for catalog.custom_field_product_settings.
 *
 * Product-specific local settings. Only valid for definitions whose itemType is
 * Product; the domain invariant is enforced by the ConfiguredFieldDefinition VO.
 *
 * @property string $id Internal UUID
 * @property string $custom_field_definition_id FK to shopwired.custom_field_definitions.id
 * @property StockItemUpdateMode|null $stock_item_update_mode
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CustomFieldProductSettingsModel extends Model implements DomainConvertibleInterface
{
    use HasUuids;

    protected $table = 'catalog.custom_field_product_settings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'stock_item_update_mode' => StockItemUpdateMode::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CustomFieldDefinitionModel, $this>
     */
    public function customFieldDefinition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinitionModel::class, 'custom_field_definition_id', 'id');
    }

    public function toDomain(): ProductFieldSettings
    {
        return new ProductFieldSettings(stockItemUpdateMode: $this->stock_item_update_mode);
    }
}
