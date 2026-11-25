<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use App\Domain\ValueObjects\TaxRate;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * One sync-source order, the builder's whole input. Absent text fields are empty strings; the shipping rate is null when absent.
 */
final readonly class SyncOrderDTO
{
    /**
     * @param list<SyncOrderLineDTO> $lines
     */
    public function __construct(
        public string $reference,
        public DateTimeImmutable $createdAt,
        public RawPaymentMethod $paymentMethod,
        public OrderContactDTO $billing,
        public string $billingEmail,
        public string $billingPhone,
        public OrderContactDTO $delivery,
        public string $shippingMethod,
        public DecimalMoney $shippingTotalNet,
        public ?TaxRate $shippingVatRate,
        public string $comments,
        public string $transactionId,
        public array $lines,
    ) {
        Assert::stringNotEmpty($reference, 'Order reference must not be empty');
    }
}
