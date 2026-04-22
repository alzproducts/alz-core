<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use App\Infrastructure\Catalog\CustomFields\Models\CustomFieldGeneralSettingsModel;
use App\Infrastructure\Catalog\CustomFields\Models\CustomFieldProductSettingsModel;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Eloquent model for shopwired.custom_field_definitions table.
 *
 * Stores ShopWired custom field definitions (schema/metadata) synced from the API.
 * The `external_id` is ShopWired's field ID, while `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired custom field ID
 * @property string $name Field identifier (snake_case)
 * @property CustomFieldType $type Field type (text, toggle, choice, list, date, date_time, value_list, product_list)
 * @property string|null $label Human-readable display label
 * @property CustomFieldItemType $item_type Entity type (product, category, customer, brand, order, page, blog_post)
 * @property int|null $sort_order Display ordering
 * @property array<int, string>|null $allowed_values Valid values for choice/list types
 * @property CarbonImmutable $created_at When first synced locally
 * @property CarbonImmutable $updated_at When last updated locally
 *
 * @implements EloquentDomainMappableInterface<CustomFieldDefinition>
 */
final class CustomFieldDefinitionModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'shopwired.custom_field_definitions';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'type' => CustomFieldType::class,
            'item_type' => CustomFieldItemType::class,
            'sort_order' => 'integer',
            'allowed_values' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasOne<CustomFieldGeneralSettingsModel, $this>
     */
    public function generalSettings(): HasOne
    {
        return $this->hasOne(CustomFieldGeneralSettingsModel::class, 'custom_field_definition_id', 'id');
    }

    /**
     * @return HasOne<CustomFieldProductSettingsModel, $this>
     */
    public function productSettings(): HasOne
    {
        return $this->hasOne(CustomFieldProductSettingsModel::class, 'custom_field_definition_id', 'id');
    }

    /**
     * Convert this Eloquent model to its corresponding Domain object.
     */
    public function toDomain(): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            id: $this->external_id,
            name: $this->name,
            type: $this->type,
            label: $this->label,
            itemType: $this->item_type,
            sortOrder: $this->sort_order,
            allowedValues: $this->allowed_values !== null ? \array_values($this->allowed_values) : null,
        );
    }

    /**
     * Convert to the read-path wrapper that pairs the ShopWired definition with local settings.
     *
     * Relies on eager-loaded `generalSettings` / `productSettings` relations; when a settings
     * row is absent, sensible defaults are used so the wrapper is always present.
     */
    public function toConfiguredDomain(): ConfiguredFieldDefinition
    {
        $base = $this->toDomain();

        return new ConfiguredFieldDefinition(
            base: $base,
            generalSettings: $this->resolveGeneralSettings(),
            productSettings: $this->resolveProductSettings($base),
        );
    }

    private function resolveGeneralSettings(): CustomFieldGeneralSettings
    {
        $model = $this->relationLoaded('generalSettings') ? $this->generalSettings : null;

        return $model instanceof CustomFieldGeneralSettingsModel
            ? $model->toDomain()
            : CustomFieldGeneralSettings::defaults();
    }

    private function resolveProductSettings(CustomFieldDefinition $base): ?ProductFieldSettings
    {
        if (! $base->isProductField() || ! $this->relationLoaded('productSettings')) {
            return null;
        }

        $model = $this->productSettings;

        return $model instanceof CustomFieldProductSettingsModel ? $model->toDomain() : null;
    }

    /**
     * Convert a Domain CustomFieldDefinition to Eloquent model attributes.
     *
     * Note: Does NOT include 'external_id' - that's used as the upsert key
     * and should be handled separately by the repository.
     *
     * @param CustomFieldDefinition $entity The domain entity to convert
     *
     * @return array<string, mixed> Attributes for Eloquent create/update
     */
    public static function fromDomainAttributes(object $entity): array
    {
        /** @var CustomFieldDefinition $entity */
        return [
            'name' => $entity->name,
            'type' => $entity->type->value,
            'label' => $entity->label,
            'item_type' => $entity->itemType->value,
            'sort_order' => $entity->sortOrder,
            'allowed_values' => $entity->allowedValues,
        ];
    }
}
