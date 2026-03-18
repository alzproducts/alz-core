<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Shared\Money\ValueObjects\Money;

/**
 * A price update command resolved against current pricing via carry-forward.
 *
 * Single source of truth for "what the effective pricing will be after applying
 * this command." Eliminates duplicate carry-forward logic by computing the
 * effective pricing once at construction time.
 *
 * Carry-forward semantics:
 * - Command field set → use command value
 * - Command field null → carry forward from current pricing
 * - Sale price is Money::inclusive(0) → clear sale (effective = null)
 */
final readonly class ResolvedPriceUpdate
{
    /**
     * @param Sku $sku The SKU being updated
     * @param UpdatePriceCommand $command The original command
     * @param ProductRetailPricing $currentPricing Pricing before the update
     * @param ProductRetailPricing $effectivePricing Resolved pricing after carry-forward
     */
    public function __construct(
        public Sku $sku,
        public UpdatePriceCommand $command,
        public ProductRetailPricing $currentPricing,
        public ProductRetailPricing $effectivePricing,
    ) {}

    /**
     * Resolve a command against current pricing using carry-forward semantics.
     */
    public static function fromCommand(
        UpdatePriceCommand $command,
        ProductRetailPricing $currentPricing,
    ): self {
        return new self(
            sku: $command->sku,
            command: $command,
            currentPricing: $currentPricing,
            effectivePricing: self::resolveEffectivePricing($command, $currentPricing),
        );
    }

    /**
     * Build effective ProductRetailPricing from command + current (carry-forward).
     *
     * Command field takes precedence; null fields carry forward from current pricing.
     * A zero sale price is converted to null (clearing the sale).
     */
    private static function resolveEffectivePricing(
        UpdatePriceCommand $command,
        ProductRetailPricing $current,
    ): ProductRetailPricing {
        $effectiveBase = $command->price ?? $current->basePrice;

        $effectiveSale = self::resolveSalePrice($command->salePrice, $current->salePrice);

        return new ProductRetailPricing(
            basePrice: $effectiveBase,
            salePrice: $effectiveSale,
        );
    }

    /**
     * Resolve sale price with zero-means-clear semantics.
     *
     * @param Money|null $commanded Sale price from command (null = no change)
     * @param Money|null $current Current sale price (null = no active sale)
     */
    private static function resolveSalePrice(?Money $commanded, ?Money $current): ?Money
    {
        if ($commanded === null) {
            return $current;
        }

        return $commanded->isZero() ? null : $commanded;
    }
}
