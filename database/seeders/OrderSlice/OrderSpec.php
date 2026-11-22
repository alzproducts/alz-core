<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;

/**
 * One seeded order before it becomes rows. `refunds` are refund values; the order's `total` is lowered by their sum.
 */
final readonly class OrderSpec
{
    /**
     * @param list<LineSpec> $lines
     * @param list<float> $refunds
     */
    public function __construct(
        public int $externalId,
        public int $reference,
        public int $placedAt,
        public RawPaymentMethod $paymentMethod,
        public OrderStatusType $status,
        public Customer $customer,
        public array $lines,
        public float $shippingNet = 4.99,
        public ?float $originalShippingNet = null,
        public bool $hasVatRelief = false,
        public array $refunds = [],
    ) {}
}
