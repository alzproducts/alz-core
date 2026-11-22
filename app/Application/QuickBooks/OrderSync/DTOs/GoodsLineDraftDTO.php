<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;

/**
 * A tax-excluded goods line: the item resolves by SKU, and QBO computes the VAT from the tax code.
 */
final readonly class GoodsLineDraftDTO
{
    public function __construct(
        public Sku $itemSku,
        public string $description,
        public DecimalMoney $unitPrice,
        public int $quantity,
        public DecimalMoney $amount,
        public TaxKey $taxKey,
    ) {}
}
