<?php

declare(strict_types=1);

namespace App\Application\Checkout;

final readonly class BasketRecoveryMatchDTO
{
    /**
     * @param array<string, mixed>|null $vatRelief
     */
    public function __construct(
        public string $basketTotal,
        public ?string $deliveryDate,
        public ?string $giftNote,
        public ?array $vatRelief,
        public string $snapshotCreatedAt,
        public string $orderNumber,
        public int $matchCount,
        public bool $multipleOrdersPlacedWithinTimeframe,
        public bool $orderMissingVatRelief,
        public bool $orderMissingGiftNote,
        public bool $orderMissingDeliveryDate,
        public bool $hasMissingData,
    ) {}
}
