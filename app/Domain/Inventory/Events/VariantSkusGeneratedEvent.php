<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Events;

/**
 * Domain event fired when variant SKUs are generated for a product.
 *
 * Dispatched from the console command after the use case completes
 * with at least one successfully created SKU.
 */
final readonly class VariantSkusGeneratedEvent
{
    /**
     * @param int $productId ShopWired product external ID
     * @param string $productTitle Product title for display
     * @param int $created Number of SKUs successfully created
     * @param int $skipped Variations already with SKUs
     * @param int $failed Variations that failed (rolled back)
     * @param list<string> $createdVariants Created variant labels (e.g., "WEB-123 - Red Large")
     */
    public function __construct(
        public int $productId,
        public string $productTitle,
        public int $created,
        public int $skipped,
        public int $failed,
        public array $createdVariants,
    ) {}
}
