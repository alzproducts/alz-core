<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use Webmozart\Assert\Assert;

/**
 * One order line: post-discount per-unit price and VAT, and the VAT rate as its decimal string (null when absent).
 */
final readonly class SyncOrderLine
{
    public function __construct(
        public Sku $sku,
        public string $title,
        public int $quantity,
        public DecimalMoney $unitPrice,
        public DecimalMoney $unitPriceVat,
        public ?string $vatRate,
    ) {
        Assert::greaterThan($quantity, 0, 'Order line quantity must be positive');
    }
}
