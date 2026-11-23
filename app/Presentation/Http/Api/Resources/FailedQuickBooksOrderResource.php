<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\QuickBooks\OrderSync\DTOs\FailedOrderSyncDTO;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin FailedOrderSyncDTO
 */
final class FailedQuickBooksOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var FailedOrderSyncDTO $item */
        $item = $this->resource;

        return [
            'reference' => $item->reference,
            'order_external_id' => $item->orderExternalId,
            'order_placed_at' => $item->placedAt?->format(DateTimeInterface::ATOM),
            'doc_type' => $item->docType?->value,
            'status' => $item->status->value,
            'attempts' => $item->attempts,
            'last_attempted_at' => $item->lastAttemptedAt->format(DateTimeInterface::ATOM),
            'reason' => [
                'code' => $item->reason->value,
                'message' => $item->message,
                'retryable' => $item->reason->retryable(),
            ],
            'warnings' => [],
        ];
    }
}
