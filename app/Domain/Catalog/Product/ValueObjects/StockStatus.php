<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use DateTimeImmutable;

/**
 * Read-side stock status flags promoted from ShopWired custom fields.
 *
 * Values are passed through verbatim — `discontinued` and `otherStockStatus`
 * are free-form strings maintained in ShopWired (no enum). `preorderDate` is
 * a parsed `DateTimeImmutable` from a date custom field.
 *
 * All properties are nullable to represent "field not set on the product".
 */
final readonly class StockStatus
{
    public function __construct(
        public ?string $discontinued,
        public ?DateTimeImmutable $preorderDate,
        public ?string $otherStockStatus,
    ) {}
}
