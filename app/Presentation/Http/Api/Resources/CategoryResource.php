<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for CategoryView domain value object.
 *
 * List view: omits description, description2, parentIds, customFields.
 *
 * @mixin CategoryView
 */
final class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CategoryView $category */
        $category = $this->resource;

        return self::baseFields($category);
    }

    /**
     * Base scalar fields shared by list and detail resources.
     *
     * @return array<string, mixed>
     */
    public static function baseFields(CategoryView $category): array
    {
        return [
            'id' => $category->id->value,
            'title' => $category->title,
            'slug' => $category->slug,
            'url' => $category->url,
            'active' => $category->active,
            'featured' => $category->featured,
            'sort_order' => $category->sortOrder,
            'meta_title' => $category->metaTitle,
            'meta_description' => $category->metaDescription,
            'image_url' => $category->image?->url,
            'created_at' => $category->createdAt->format(DateTimeInterface::ATOM),
        ];
    }
}
