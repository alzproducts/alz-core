<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

/**
 * Stock level data for a product, sourced from Linnworks.
 *
 * All quantity fields are nullable: null means Linnworks didn't provide the value,
 * while 0 means the stock level is genuinely zero. No coalescing is applied —
 * the assembler passes raw nullable values straight through from StockItemModel.
 *
 * Available via ?include=stock on both list and detail product endpoints.
 */
final readonly class ProductStock
{
    /**
     * @param int|null $quantity Total on-hand quantity (null = not provided by Linnworks)
     * @param int|null $available Available to sell (null = not provided by Linnworks)
     * @param int|null $inOrder Quantity in open purchase orders (null = not provided)
     * @param int|null $due Quantity due to arrive (null = not provided)
     * @param bool $jit Just-in-time flag (true = item is drop-shipped / never stocked)
     */
    public function __construct(
        public ?int $quantity,
        public ?int $available,
        public ?int $inOrder,
        public ?int $due,
        public bool $jit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quantity' => $this->quantity,
            'available' => $this->available,
            'in_order' => $this->inOrder,
            'due' => $this->due,
            'jit' => $this->jit,
        ];
    }
}
