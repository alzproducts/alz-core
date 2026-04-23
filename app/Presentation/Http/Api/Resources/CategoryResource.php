<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

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
    #[Override]
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
            'links' => [
                'public_url' => $category->links->publicUrl,
                'edit_website_url' => $category->links->editWebsiteUrl,
            ],
            'active' => $category->active,
            'featured' => $category->featured,
            'is_main_category' => $category->isMainCategory,
            'sort_order' => $category->sortOrder,
            'meta_title' => $category->metaTitle,
            'meta_description' => $category->metaDescription,
            'image_url' => $category->image?->url,
            'created_at' => $category->createdAt->format(DateTimeInterface::ATOM),
        ];
    }
}
