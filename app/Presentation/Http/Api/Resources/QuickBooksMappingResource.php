<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\QuickBooks\Mappings\DTOs\MappingStatusDTO;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * `unresolved` and `other_realm` both block a sync.
 *
 * @mixin MappingStatusDTO
 */
final class QuickBooksMappingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var MappingStatusDTO $item */
        $item = $this->resource;
        $mapping = $item->mapping;

        return [
            'key' => $mapping->key->value,
            'entity' => $mapping->key->entity()->value,
            'ref_id' => $mapping->refId,
            'ref_name' => $mapping->refName,
            'realm_id' => $mapping->realmId,
            'resolved_at' => $mapping->resolvedAt?->format(DateTimeInterface::ATOM),
            'state' => $item->state->value,
        ];
    }
}
