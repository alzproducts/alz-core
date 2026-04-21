<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

/**
 * Stock information for a product.
 *
 * Master-level fields (quantity, available, inOrder, due, jit) are nullable:
 * null means the product has no master inventory record — either because the
 * upstream inventory source didn't provide a value, or because the product
 * tracks stock at the variation level rather than on a master SKU.
 *
 * Aggregate fields (aggregateAvailable, aggregatePhysical) are always populated:
 * they sum across variations when present, otherwise fall back to master values.
 *
 * Available via ?include=stock on both list and detail product endpoints.
 */
final readonly class ProductStock
{
    /**
     * @param int|null $quantity Total on-hand quantity at the master level (null = no master record)
     * @param int|null $available Available to sell at the master level (null = no master record)
     * @param int|null $inOrder Quantity in open purchase orders (null = no master record)
     * @param int|null $due Quantity due to arrive (null = no master record)
     * @param bool $jit Just-in-time flag (true = item is drop-shipped / never stocked)
     * @param int $aggregateAvailable Available stock summed across variations (or master value if no variations)
     * @param int $aggregatePhysical Physical stock summed across variations (or master value if no variations)
     */
    public function __construct(
        public ?int $quantity,
        public ?int $available,
        public ?int $inOrder,
        public ?int $due,
        public bool $jit,
        public int $aggregateAvailable,
        public int $aggregatePhysical,
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
            'aggregate_available' => $this->aggregateAvailable,
            'aggregate_physical' => $this->aggregatePhysical,
        ];
    }
}
