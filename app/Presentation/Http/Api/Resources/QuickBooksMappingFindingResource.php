<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\QuickBooks\Mappings\DTOs\MappingFindingDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin MappingFindingDTO
 */
final class QuickBooksMappingFindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var MappingFindingDTO $finding */
        $finding = $this->resource;

        return [
            'key' => $finding->key->value,
            // intentional: the enum's values hold spaces; the API sends snake_case.
            'kind' => \str_replace(' ', '_', $finding->kind->value),
            'stored_name' => $finding->storedName,
            'current_name' => $finding->currentName,
        ];
    }
}
