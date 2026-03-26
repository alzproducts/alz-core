<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\Brand\ValueObjects\BrandView;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for BrandView domain value object.
 *
 * List view: omits description and customFields.
 *
 * @mixin BrandView
 */
final class BrandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BrandView $brand */
        $brand = $this->resource;

        return self::baseFields($brand);
    }

    /**
     * Base scalar fields shared by list and detail resources.
     *
     * @return array<string, mixed>
     */
    public static function baseFields(BrandView $brand): array
    {
        return [
            'id' => $brand->id->value,
            'title' => $brand->title,
            'slug' => $brand->slug,
            'url' => $brand->url,
            'active' => $brand->active,
            'featured' => $brand->featured,
            'sort_order' => $brand->sortOrder,
            'meta_title' => $brand->metaTitle,
            'meta_description' => $brand->metaDescription,
            'image_url' => $brand->image?->url,
            'created_at' => $brand->createdAt->format(DateTimeInterface::ATOM),
        ];
    }
}
