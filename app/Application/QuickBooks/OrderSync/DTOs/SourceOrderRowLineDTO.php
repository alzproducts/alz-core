<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * One source-order line as stored, unchecked: money and the percentage rate are decimal strings.
 */
final readonly class SourceOrderRowLineDTO
{
    public function __construct(
        public string $sku,
        public string $title,
        public int $quantity,
        public string $unitPrice,
        public string $unitPriceVat,
        public ?string $vatRate,
    ) {}
}
