<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * One sync-source order, the builder's whole input. Absent text fields are empty strings; the shipping rate is null when absent.
 */
final readonly class SyncOrder
{
    /**
     * @param list<SyncOrderLine> $lines
     */
    public function __construct(
        public string $reference,
        public DateTimeImmutable $createdAt,
        public bool $cancelled,
        public RawPaymentMethod $paymentMethod,
        public OrderContact $billing,
        public string $billingEmail,
        public string $billingPhone,
        public OrderContact $delivery,
        public string $shippingMethod,
        public DecimalMoney $shippingTotalNet,
        public ?string $shippingVatRate,
        public string $comments,
        public string $transactionId,
        public array $lines,
    ) {
        Assert::stringNotEmpty($reference, 'Order reference must not be empty');
    }
}
