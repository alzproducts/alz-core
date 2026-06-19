<?php

declare(strict_types=1);

namespace App\Presentation\Http\Checkout\Resources;

use App\Application\Checkout\DTOs\BasketRecoveryMatchDTO;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin BasketRecoveryMatchDTO
 */
final class BasketRecoveryMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var BasketRecoveryMatchDTO $match */
        $match = $this->resource;

        return [
            'basket_total' => $match->basketTotal->toGross(),
            'delivery_date' => $match->deliveryDate?->format('Y-m-d'),
            'gift_note' => $match->giftNote,
            'vat_relief' => $match->vatRelief,
            'snapshot_created_at' => $match->snapshotCreatedAt->format(DateTimeInterface::ATOM),
            'order_number' => $match->orderNumber,
            'match_count' => $match->matchCount,
            'multiple_orders_placed_within_timeframe' => $match->multipleOrdersPlacedWithinTimeframe,
            'order_missing_vat_relief' => $match->orderMissingVatRelief,
            'order_missing_gift_note' => $match->orderMissingGiftNote,
            'order_missing_delivery_date' => $match->orderMissingDeliveryDate,
            'has_missing_data' => $match->hasMissingData,
        ];
    }
}
