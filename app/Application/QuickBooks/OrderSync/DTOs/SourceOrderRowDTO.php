<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use DateTimeImmutable;

/**
 * A sync-source order as stored, unchecked: absent text is empty, an absent payment method or rate is null.
 */
final readonly class SourceOrderRowDTO
{
    /**
     * @param list<SourceOrderRowLineDTO> $lines
     */
    public function __construct(
        public int $reference,
        public int $externalId,
        public DateTimeImmutable $createdAt,
        public OrderLifecycleStatus $status,
        public ?string $paymentMethod,
        public OrderContactDTO $billing,
        public string $billingEmail,
        public string $billingPhone,
        public OrderContactDTO $delivery,
        public string $shippingMethod,
        public string $shippingTotalNet,
        public ?string $shippingVatRate,
        public string $comments,
        public string $transactionId,
        public array $lines,
    ) {}
}
