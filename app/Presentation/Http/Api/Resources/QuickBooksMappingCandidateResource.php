<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\QuickBooks\Mappings\DTOs\MappingOptionDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin MappingOptionDTO
 */
final class QuickBooksMappingCandidateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var MappingOptionDTO $option */
        $option = $this->resource;

        return [
            'id' => $option->candidate->id,
            'name' => $option->candidate->name,
            'detail' => $option->candidate->detail,
            'current' => $option->current,
        ];
    }
}
