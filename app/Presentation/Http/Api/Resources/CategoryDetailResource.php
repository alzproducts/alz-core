<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetCategoryResult;
use App\Domain\Catalog\Category\Enums\CategoryInclude;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\ValueObjects\IntId;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for single category detail with conditional embeds.
 *
 * Wraps GetCategoryResult to access both the category and the includes list.
 * Base fields match CategoryResource; embeds are added conditionally.
 *
 * @mixin GetCategoryResult
 */
final class CategoryDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var GetCategoryResult $result */
        $result = $this->resource;

        return CategoryResource::baseFields($result->category)
            + $this->conditionalIncludes($result);
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionalIncludes(GetCategoryResult $result): array
    {
        $category = $result->category;
        $data = [];
        if ($result->hasInclude(CategoryInclude::Description)) {
            $data['description'] = $category->description;
        }
        if ($result->hasInclude(CategoryInclude::Description2)) {
            $data['description2'] = $category->description2;
        }
        if ($result->hasInclude(CategoryInclude::ParentIds) && $category->parentIds !== null) {
            $data['parent_ids'] = \array_map(static fn(IntId $id): int => $id->value, $category->parentIds);
        }
        if ($result->hasInclude(CategoryInclude::CustomFields) && $category->customFields !== null) {
            $data['custom_fields'] = \array_map(static fn(AbstractCustomFieldValue $field): array => $field->toArray(), $category->customFields);
        }
        return $data;
    }
}
