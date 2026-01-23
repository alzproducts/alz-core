<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Eloquent model for shopwired.custom_field_definitions table.
 *
 * Stores ShopWired custom field definitions (schema/metadata) synced from the API.
 * The `external_id` is ShopWired's field ID, while `id` is our internal UUID.
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired custom field ID
 * @property string $name Field identifier (snake_case)
 * @property string $type Field type (text, toggle, choice, list, date, date_time, value_list, product_list)
 * @property string|null $label Human-readable display label
 * @property string $item_type Entity type (product, category, customer, brand, order, page, blog_post)
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
            'sort_order' => 'integer',
            'allowed_values' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Convert this Eloquent model to its corresponding Domain object.
     *
     * @throws InvalidApiResponseException When stored enum values are invalid (indicates data corruption)
     */
    public function toDomain(): CustomFieldDefinition
    {
        $type = CustomFieldType::tryFrom($this->type);
        $itemType = CustomFieldItemType::tryFrom($this->item_type);

        if ($type === null) {
            Log::critical('Invalid CustomFieldType in database - possible API schema change', [
                'external_id' => $this->external_id,
                'type' => $this->type,
            ]);

            throw new InvalidApiResponseException(
                serviceName: 'Shopwired',
                message: "Unknown custom field type '{$this->type}' for definition {$this->external_id}. API schema may have changed.",
            );
        }

        if ($itemType === null) {
            Log::critical('Invalid CustomFieldItemType in database - possible API schema change', [
                'external_id' => $this->external_id,
                'item_type' => $this->item_type,
            ]);

            throw new InvalidApiResponseException(
                serviceName: 'Shopwired',
                message: "Unknown custom field item type '{$this->item_type}' for definition {$this->external_id}. API schema may have changed.",
            );
        }

        return new CustomFieldDefinition(
            id: $this->external_id,
            name: $this->name,
            type: $type,
            label: $this->label,
            itemType: $itemType,
            sortOrder: $this->sort_order,
            allowedValues: $this->allowed_values !== null ? \array_values($this->allowed_values) : null,
        );
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
