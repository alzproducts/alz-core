<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use DateTimeImmutable;

/**
 * Sale metadata threaded through the pricing event chain.
 *
 * - For add-to-sale: saleReason required, comments/dates optional
 * - For auto-removal: saleReason + removalReason populated
 */
final readonly class SaleSettings
{
    public function __construct(
        public string $saleReason,
        public ?string $saleComments = null,
        public ?DateTimeImmutable $saleStartDate = null,
        public ?DateTimeImmutable $saleEndDate = null,
        public ?int $saleEndsStock = null,
        public ?SaleRemovalReason $removalReason = null,
    ) {}

    /**
     * Create settings for an automatic sale removal.
     */
    public static function forRemoval(SaleRemovalReason $reason): self
    {
        return new self(
            saleReason: $reason->label(),
            removalReason: $reason,
        );
    }
}
