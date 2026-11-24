<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;

/**
 * An order's stored net and VAT amounts, as decimal text. A refund changes only the order's `total`, which is left out.
 */
final readonly class SourceOrderTotalsDTO
{
    public function __construct(
        public int $reference,
        public OrderLifecycleStatus $status,
        public string $subTotalNet,
        public string $shippingTotalNet,
        public ?string $taxValue,
    ) {}
}
