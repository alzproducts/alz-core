<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;

final readonly class ShippingLineDraft
{
    public function __construct(
        public DecimalMoney $amount,
        public TaxKey $taxKey,
    ) {}
}
