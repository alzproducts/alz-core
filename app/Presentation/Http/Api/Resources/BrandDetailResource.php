<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Catalog\UseCases\GetBrandResult;
use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

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
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var GetBrandResult $result */
        $result = $this->resource;
        $brand = $result->brand;

        $data = BrandResource::baseFields($brand);

        if ($result->hasInclude(BrandInclude::Description)) {
            $data['description'] = $brand->description;
        }

        if ($result->hasInclude(BrandInclude::CustomFields) && $brand->customFields !== null) {
            $data['custom_fields'] = \array_map(
                static fn(AbstractCustomFieldValue $field): array => $field->toArray(),
                $brand->customFields,
            );
        }

        return $data;
    }
}
