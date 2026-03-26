<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetBrandResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for single brand detail with conditional embeds.
 *
 * Wraps GetBrandResult to access both the brand and the includes list.
 * Base fields match BrandResource; embeds are added conditionally.
 *
 * @mixin GetBrandResult
 */
final class BrandDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var GetBrandResult $result */
        $result = $this->resource;
        $brand = $result->brand;

        $data = BrandResource::baseFields($brand);

        if ($result->hasInclude('description')) {
            $data['description'] = $brand->description;
        }

        if ($result->hasInclude('custom_fields')) {
            $data['custom_fields'] = $brand->customFields;
        }

        return $data;
    }
}
