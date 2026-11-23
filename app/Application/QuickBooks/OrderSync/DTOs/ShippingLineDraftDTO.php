<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;

final readonly class ShippingLineDraftDTO
{
    public function __construct(
        public DecimalMoney $amount,
        public TaxKey $taxKey,
    ) {}
}
