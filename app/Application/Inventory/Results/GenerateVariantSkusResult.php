<?php

declare(strict_types=1);

namespace App\Application\Inventory\Results;

/**
 * Result of generating variant SKUs for a product.
 *
 * @template-pattern Application Result VO
 */
final readonly class GenerateVariantSkusResult
{
    /**
     * @param int $total Total variations on the product
     * @param int $skipped Variations skipped (already had SKU)
     * @param int $created Variations successfully created in Linnworks
     * @param int $failed Variations that failed (rolled back)
     * @param string $productTitle Product title for display/notifications
     * @param list<string> $createdVariants Created variant labels (e.g., "WEB-123 - Red Large")
     * @param list<int> $failedVariationIds Variation external IDs that failed
     */
    public function __construct(
        public int $total,
        public int $skipped,
        public int $created,
        public int $failed,
        public string $productTitle = '',
        public array $createdVariants = [],
        public array $failedVariationIds = [],
    ) {}

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    public function allSucceeded(): bool
    {
        return $this->failed === 0;
    }

    /**
     * Create a result for when the product has no variations.
     */
    public static function noVariations(string $productTitle = ''): self
    {
        return new self(
            total: 0,
            skipped: 0,
            created: 0,
            failed: 0,
            productTitle: $productTitle,
        );
    }

    /**
     * Create a result for when all variations already have SKUs.
     */
    public static function allSkipped(int $total, string $productTitle = ''): self
    {
        return new self(
            total: $total,
            skipped: $total,
            created: 0,
            failed: 0,
            productTitle: $productTitle,
        );
    }
}
