<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API resource for {@see ConfiguredFieldDefinition}.
 *
 * The `general` block is always present — when no settings row exists, its
 * fields reflect their default values (null for nullable columns, `false` for
 * `admin_only`). The `product` block is `null` for non-product entities; for
 * product entities it is always an object (defaults to `{stock_item_update_mode: null}`
 * when no settings row exists).
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
            'general' => (new CustomFieldGeneralSettingsResource($definition->generalSettings))->toArray($request),
            'product' => $definition->base->isProductField()
                ? (new ProductFieldSettingsResource($definition->productSettings))->toArray($request)
                : null,
        ];
    }
}
