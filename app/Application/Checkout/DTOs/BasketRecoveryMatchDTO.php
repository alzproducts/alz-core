<?php

declare(strict_types=1);

namespace App\Application\Checkout\DTOs;

use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;

final readonly class BasketRecoveryMatchDTO
{
    /**
     * @param array<string, mixed>|null $vatRelief
     */
    public function __construct(
        public Money $basketTotal,
        public ?DateTimeImmutable $deliveryDate,
        public ?string $giftNote,
        public ?array $vatRelief,
        public DateTimeImmutable $snapshotCreatedAt,
        public string $orderNumber,
        public int $matchCount,
        public bool $multipleOrdersPlacedWithinTimeframe,
        public bool $orderMissingVatRelief,
        public bool $orderMissingGiftNote,
        public bool $orderMissingDeliveryDate,
        public bool $hasMissingData,
    ) {}
}
