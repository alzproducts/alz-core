<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetCategoryResult;
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
        $category = $result->category;

        $data = CategoryResource::baseFields($category);

        if ($result->hasInclude('description')) {
            $data['description'] = $category->description;
        }

        if ($result->hasInclude('description2')) {
            $data['description2'] = $category->description2;
        }

        if ($result->hasInclude('parent_ids') && $category->parentIds !== null) {
            $data['parent_ids'] = \array_map(
                static fn(IntId $id): int => $id->value,
                $category->parentIds,
            );
        }

        if ($result->hasInclude('custom_fields')) {
            $data['custom_fields'] = $category->customFields;
        }

        return $data;
    }
}
