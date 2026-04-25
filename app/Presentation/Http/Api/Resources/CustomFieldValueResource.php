<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API resource for custom field values.
 *
 * Single source of truth for the custom-field-value JSON shape.
 * Owns all wire-format decisions (snake_case keys, ATOM date formatting, enum stringification)
 * so the Domain layer stays framework-agnostic.
 *
 * @mixin AbstractCustomFieldValue
 */
final class CustomFieldValueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var AbstractCustomFieldValue $field */
        $field = $this->resource;

        return [
            'name' => $field->name(),
            'type' => $field->type()->value,
            'label' => $field->label(),
            'value' => match (true) {
                $field instanceof DateTimeCustomFieldValue => $field->value->format(DateTimeInterface::ATOM),
                default => $field->rawValue(),
            },
            'allowed_values' => $field->definition->base->allowedValues,
            'sort_order' => $field->definition->base->sortOrder,
        ];
    }
}
