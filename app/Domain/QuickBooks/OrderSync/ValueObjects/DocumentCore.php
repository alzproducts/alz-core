<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\QuickBooks\OrderSync\Enums\TaxCalculation;
use DateTimeImmutable;

/**
 * The fields an invoice and a sales receipt share.
 */
final readonly class DocumentCore
{
    /**
     * @param non-empty-list<GoodsLineDraft> $goodsLines
     */
    public function __construct(
        public string $docNumber,
        public DateTimeImmutable $txnDate,
        public string $billEmail,
        public CustomerDraft $customer,
        public array $goodsLines,
        public ?ShippingLineDraft $shippingLine,
    ) {}

    public function taxCalculation(): TaxCalculation
    {
        return TaxCalculation::TaxExcluded;
    }
}
