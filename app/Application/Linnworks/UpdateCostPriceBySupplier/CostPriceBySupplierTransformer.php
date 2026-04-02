<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UpdateCostPriceBySupplier;

use App\Application\Catalog\Results\CostPriceUpdateResult;
use App\Application\Catalog\Results\FailedCostPriceUpdateResult;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\StockItemSupplierStat;
use App\Domain\ValueObjects\Guid;
use Webmozart\Assert\Assert;

/**
 * Pure data transformations for bulk cost price updates.
 *
 * Stateless helper — all methods are static with no side effects.
 */
final readonly class CostPriceBySupplierTransformer
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
     * Extract stock item GUIDs for the resolved commands.
     *
     * @param list<UpdateCostPriceCommand> $resolved Already-resolved commands
     * @param array<string, Guid> $skuToGuid SKU → stockItemId mapping
     *
     * @return list<Guid>
     */
    public static function extractStockItemGuids(array $resolved, array $skuToGuid): array
    {
        $seen = [];
        $guids = [];

        foreach ($resolved as $cmd) {
            $guid = $skuToGuid[$cmd->sku->value] ?? null;
            Assert::notNull($guid, "SKU {$cmd->sku->value} should be resolved at this point");

            if (! isset($seen[$guid->value])) {
                $seen[$guid->value] = true;
                $guids[] = $guid;
            }
        }

        return $guids;
    }

    /**
     * Merge new purchase prices into fetched supplier stats.
     *
     * For each resolved command, finds the matching supplier stat by SupplierID
     * and returns a new VO with the updated purchase price.
     * Commands where the supplier stat is not found are added to failures.
     *
     * @param list<UpdateCostPriceCommand> $resolved Commands that had their SKU resolved
     * @param array<string, Guid> $skuToGuid SKU → stockItemId mapping
     * @param Guid $supplierGuid Pre-resolved supplier GUID
     * @param array<string, list<StockItemSupplierStat>> $statsByStockItem stockItemId → supplier stats
     *
     * @return array{list<StockItemSupplierStat>, list<FailedCostPriceUpdateResult>}
     */
    public static function mergeSupplierPrices(
        array $resolved,
        array $skuToGuid,
        Guid $supplierGuid,
        array $statsByStockItem,
    ): array {
        $merged = [];
        $failures = [];
        foreach ($resolved as $cmd) {
            $stockItemGuid = $skuToGuid[$cmd->sku->value] ?? null;
            Assert::notNull($stockItemGuid, "SKU {$cmd->sku->value} should be resolved at this point");
            $matchingStat = self::findMatchingStat($statsByStockItem, $stockItemGuid, $supplierGuid);
            if ($matchingStat === null) {
                $failures[] = new FailedCostPriceUpdateResult($cmd->sku, 'Supplier stat not found in Linnworks');
            } else {
                $merged[] = $matchingStat->withPurchasePrice($cmd->costPrice);
            }
        }

        return [$merged, $failures];
    }

    /**
     * @param array<string, list<StockItemSupplierStat>> $statsByStockItem
     */
    private static function findMatchingStat(
        array $statsByStockItem,
        Guid $stockItemGuid,
        Guid $supplierGuid,
    ): ?StockItemSupplierStat {
        $stats = $statsByStockItem[\mb_strtolower($stockItemGuid->value)] ?? [];

        return \array_find($stats, static fn(StockItemSupplierStat $s): bool => $s->supplierId->equals($supplierGuid));
    }

    /**
     * Convert resolved commands to failure results when the bulk API call fails.
     *
     * @param list<UpdateCostPriceCommand> $resolved Commands that were resolved but failed at API level
     *
     * @return list<FailedCostPriceUpdateResult>
     */
    public static function buildApiFailures(array $resolved, string $error): array
    {
        return \array_map(
            static fn(UpdateCostPriceCommand $cmd): FailedCostPriceUpdateResult => new FailedCostPriceUpdateResult($cmd->sku, $error),
            $resolved,
        );
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

    /**
     * Build SKU → net purchase price map for succeeded items only.
     *
     * @param list<UpdateCostPriceCommand> $commands
     *
     * @return array<string, float>
     */
    public static function buildSucceededPriceMap(array $commands, CostPriceUpdateResult $result): array
    {
        $failedSkus = self::buildFailedSkuLookup($result);
        $pricesBySku = [];

        foreach ($commands as $command) {
            if (! isset($failedSkus[$command->sku->value])) {
                $pricesBySku[$command->sku->value] = $command->costPrice->toNet();
            }
        }

        return $pricesBySku;
    }
}
