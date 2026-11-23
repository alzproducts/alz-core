<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Application\Shopwired\DTOs\OrderStatusSummaryDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin OrderStatusSummaryDTO
 */
final class OrderStatusSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var OrderStatusSummaryDTO $summary */
        $summary = $this->resource;

        return [
            'pending_paid_count' => $summary->pendingPaidCount,
            'unpaid_order_references' => $summary->unpaidOrderReferences,
            'errored_paid_count' => $summary->erroredPaidCount,
        ];
    }
}
