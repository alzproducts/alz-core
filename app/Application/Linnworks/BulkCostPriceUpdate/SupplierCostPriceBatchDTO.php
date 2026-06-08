<?php

declare(strict_types=1);

namespace App\Application\Linnworks\BulkCostPriceUpdate;

use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use Webmozart\Assert\Assert;

/**
 * One supplier and its full set of cost-price commands, prior to chunking.
 *
 * Supplier is the forced grouping key: SkuSupplierLinkValidator fails the whole batch if
 * any SKU lacks the supplier, so commands cannot be mixed across suppliers in one job.
 */
final readonly class SupplierCostPriceBatchDTO
{
    /**
     * @param list<UpdateCostPriceCommand> $commands
     */
    public function __construct(
        public string $supplierName,
        public array $commands,
    ) {
        Assert::notEmpty($commands, 'A supplier batch requires at least one cost price command');
    }
}
