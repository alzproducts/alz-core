<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\Results;

use App\Domain\Catalog\Product\ValueObjects\ResolvedPriceUpdate;

/**
 * Outcome of pre-flight validation for a batch of price update commands.
 *
 * Separates validated commands (ready for API) from skipped (unchanged)
 * and permanently failed (invalid) commands.
 */
final readonly class PreFlightValidationResult
{
    /**
     * @param list<ResolvedPriceUpdate> $validated Commands that passed all checks, resolved with carry-forward
     * @param list<SkippedPriceUpdateResult> $skipped Pre-flight: prices unchanged
     * @param list<FailedPriceUpdateResult> $permanentFailures Validation rejected (ownership, price relationships)
     */
    public function __construct(
        public array $validated,
        public array $skipped,
        public array $permanentFailures,
    ) {}

    public function hasValidated(): bool
    {
        return $this->validated !== [];
    }

    /**
     * Build a lookup map of resolved updates keyed by SKU value.
     *
     * @return array<string, ResolvedPriceUpdate>
     */
    public function resolvedBySku(): array
    {
        $map = [];

        foreach ($this->validated as $resolved) {
            $map[$resolved->sku->value] = $resolved;
        }

        return $map;
    }
}
