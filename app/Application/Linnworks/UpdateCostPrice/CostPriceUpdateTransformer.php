<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UpdateCostPrice;

use App\Application\Catalog\Results\CostPriceUpdateResult;
use App\Application\Catalog\Results\FailedCostPriceUpdateResult;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use Webmozart\Assert\Assert;

/**
 * Pure data transformations for bulk cost price updates.
 *
 * Stateless helper — all methods are static with no side effects.
 */
final readonly class CostPriceUpdateTransformer
{
    /**
     * Extract unique SKUs from a list of commands.
     *
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     *
     * @return list<Sku>
     */
    public static function extractUniqueSkus(array $commands): array
    {
        $seen = [];
        $unique = [];

        foreach ($commands as $command) {
            $value = $command->sku->value;
            if (! isset($seen[$value])) {
                $seen[$value] = true;
                $unique[] = $command->sku;
            }
        }

        return $unique;
    }

    /**
     * Partition commands into resolved (SKU found) and failed (SKU not found).
     *
     * @param list<UpdateCostPriceCommand> $commands
     * @param array<string, Guid> $skuToGuid
     *
     * @return array{list<UpdateCostPriceCommand>, list<FailedCostPriceUpdateResult>}
     */
    public static function partitionByResolution(array $commands, array $skuToGuid): array
    {
        $resolved = [];
        $failures = [];

        foreach ($commands as $command) {
            if (isset($skuToGuid[$command->sku->value])) {
                $resolved[] = $command;
            } else {
                $failures[] = new FailedCostPriceUpdateResult($command->sku, 'SKU not found in Linnworks');
            }
        }

        return [$resolved, $failures];
    }

    /**
     * Build stockItemId → purchase price map for the bulk API call.
     *
     * @param list<UpdateCostPriceCommand> $commands Already-resolved commands
     * @param array<string, Guid> $skuToGuid SKU → stockItemId mapping
     *
     * @return array<string, Money> stockItemId GUID string → purchase price
     */
    public static function buildPriceMap(array $commands, array $skuToGuid): array
    {
        $map = [];

        foreach ($commands as $cmd) {
            $stockItemId = $skuToGuid[$cmd->sku->value] ?? null;
            Assert::notNull($stockItemId, "SKU {$cmd->sku->value} should be resolved at this point");
            $map[$stockItemId->value] = $cmd->costPrice;
        }

        return $map;
    }

    /**
     * Build a lookup set of failed SKU values for O(1) membership checks.
     *
     * @return array<string, true>
     */
    public static function buildFailedSkuLookup(CostPriceUpdateResult $result): array
    {
        $failedSkus = [];

        foreach ($result->failures as $failure) {
            $failedSkus[$failure->sku->value] = true;
        }

        return $failedSkus;
    }
}
