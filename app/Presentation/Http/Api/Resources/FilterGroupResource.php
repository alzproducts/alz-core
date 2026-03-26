<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API resource for FilterGroupDefinition domain value object.
 *
 * Simple entity — all fields returned, no conditional includes.
 *
 * @mixin FilterGroupDefinition
 */
final class FilterGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FilterGroupDefinition $filterGroup */
        $filterGroup = $this->resource;

        return [
            'id' => $filterGroup->id,
            'title' => $filterGroup->title,
            'option_no' => $filterGroup->optionNo,
            'sort_order' => $filterGroup->sortOrder,
        ];
    }
}
