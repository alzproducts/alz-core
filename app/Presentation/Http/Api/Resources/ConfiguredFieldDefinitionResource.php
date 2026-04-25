<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API resource for {@see ConfiguredFieldDefinition}.
 *
 * The `general` block is always present — when no settings row exists, its
 * fields reflect their default values (null for nullable columns, `false` for
 * `admin_only`). The `product` block is `null` unless the definition's
 * item_type is `product` AND a settings row exists.
 *
 * @mixin ConfiguredFieldDefinition
 */
final class ConfiguredFieldDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var ConfiguredFieldDefinition $definition */
        $definition = $this->resource;

        return [
            'id' => $definition->base->id,
            'internal_id' => $definition->internalId->value,
            'name' => $definition->base->name,
            'type' => $definition->base->type->value,
            'label' => $definition->base->label,
            'item_type' => $definition->base->itemType->value,
            'sort_order' => $definition->base->sortOrder,
            'allowed_values' => $definition->base->allowedValues,
            'general' => self::generalBlock($definition->generalSettings),
            'product' => self::productBlock($definition),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function generalBlock(?CustomFieldGeneralSettings $settings): array
    {
        return [
            'tooltip' => $settings?->tooltip,
            'select_type' => $settings?->selectType?->value,
            'suggest_common_data' => $settings?->suggestCommonData,
            'admin_only' => $settings === null ? false : $settings->adminOnly,
            'field_validation_rule' => $settings?->validationRule?->value,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function productBlock(ConfiguredFieldDefinition $definition): ?array
    {
        if (! $definition->base->isProductField()) {
            return null;
        }

        $product = $definition->productSettings;

        if ($product === null) {
            return null;
        }

        return self::productPayload($product);
    }

    /**
     * @return array<string, mixed>
     */
    private static function productPayload(ProductFieldSettings $settings): array
    {
        return [
            'stock_item_update_mode' => $settings->stockItemUpdateMode?->value,
        ];
    }
}
