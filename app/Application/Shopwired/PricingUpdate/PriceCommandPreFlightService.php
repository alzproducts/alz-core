<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate;

use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\PreFlightValidationResult;
use App\Application\Shopwired\PricingUpdate\Results\SkippedPriceUpdateResult;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Validators\HasValidRetailPricingValidator;
use App\Domain\Catalog\Product\Validators\PriceChangedValidator;
use App\Domain\Catalog\Product\Validators\PriceCommandsVatRoundTripValidator;
use App\Domain\Catalog\Product\Validators\SkuBelongsToProductValidator;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\ResolvedPriceUpdate;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\TaxRate;
use Webmozart\Assert\Assert;

/**
 * Pre-flight validation for price update commands.
 *
 * Validates VAT round-trips, SKU ownership, unchanged prices, and price relationships.
 * Stateless — all methods are static.
 */
final class PriceCommandPreFlightService
{
    /**
     * Validate all submitted prices survive the VAT gross→net→gross round trip.
     *
     * Runs before any DB/API work so invalid prices are rejected immediately.
     *
     * @param list<UpdatePriceCommand> $commands
     *
     * @throws ValidationFailedException When any price fails the round-trip check
     */
    public static function validateVatRoundTrip(array $commands): void
    {
        (new PriceCommandsVatRoundTripValidator(
            commands: $commands,
            taxRate: TaxRate::standard(),
        ))->validate()->orFail();
    }

    /**
     * Validate commands: ownership, unchanged, price relationships.
     *
     * @param list<UpdatePriceCommand> $skuUpdates
     * @param array<string, ProductRetailPricing> $currentPrices
     */
    public static function validateCommands(
        array $skuUpdates,
        Product $product,
        array $currentPrices,
    ): PreFlightValidationResult {
        /** @var list<SkippedPriceUpdateResult> $skipped */
        $skipped = [];
        /** @var list<FailedPriceUpdateResult> $permanentFailures */
        $permanentFailures = [];
        /** @var list<ResolvedPriceUpdate> $validated */
        $validated = [];

        // 1. SKU ownership check (batch-level, gates everything)
        $submittedSkus = \array_map(
            static fn(UpdatePriceCommand $cmd): Sku => $cmd->sku,
            $skuUpdates,
        );

        $ownershipResult = (new SkuBelongsToProductValidator(
            product: $product,
            requiredSkus: $submittedSkus,
        ))->validate();

        /** @var array<string, true> $unownedLookup */
        $unownedLookup = [];
        foreach ($ownershipResult->missingSkus() as $sku) {
            $unownedLookup[$sku->value] = true;
        }

        foreach ($skuUpdates as $command) {
            if (isset($unownedLookup[$command->sku->value])) {
                $permanentFailures[] = new FailedPriceUpdateResult(
                    sku: $command->sku,
                    error: "SKU does not belong to product {$product->id}",
                );

                continue;
            }

            $currentPricing = $currentPrices[$command->sku->value] ?? null;
            Assert::notNull($currentPricing, "Owned SKU {$command->sku->value} must have pricing data");

            $outcome = self::validateSingleCommand($command, $currentPricing);

            match (true) {
                $outcome instanceof ResolvedPriceUpdate => $validated[] = $outcome,
                $outcome instanceof SkippedPriceUpdateResult => $skipped[] = $outcome,
                $outcome instanceof FailedPriceUpdateResult => $permanentFailures[] = $outcome,
            };
        }

        return new PreFlightValidationResult(
            validated: $validated,
            skipped: $skipped,
            permanentFailures: $permanentFailures,
        );
    }

    /**
     * Validate a single command: resolve carry-forward, check unchanged, check price relationships.
     */
    private static function validateSingleCommand(
        UpdatePriceCommand $command,
        ProductRetailPricing $currentPricing,
    ): ResolvedPriceUpdate|SkippedPriceUpdateResult|FailedPriceUpdateResult {
        // Resolve effective pricing via carry-forward (single source of truth)
        $resolved = ResolvedPriceUpdate::fromCommand($command, $currentPricing);

        // Skip unchanged prices (soft: failed = skip)
        $changeResult = (new PriceChangedValidator(
            proposed: $resolved->effectivePricing,
            current: $currentPricing,
        ))->validate();

        if ($changeResult->failed()) {
            return new SkippedPriceUpdateResult(
                sku: $command->sku,
                reason: $changeResult->reason(),
            );
        }

        // Validate price relationships (soft: failed = permanent failure)
        $pricingResult = (new HasValidRetailPricingValidator(
            pricing: $resolved->effectivePricing,
        ))->validate();

        if ($pricingResult->failed()) {
            return new FailedPriceUpdateResult(
                sku: $command->sku,
                error: $pricingResult->reason(),
            );
        }

        return $resolved;
    }
}
