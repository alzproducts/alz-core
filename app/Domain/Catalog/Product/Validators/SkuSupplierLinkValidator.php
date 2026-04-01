<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Validation\Contracts\ValidatorInterface;

/**
 * Validates that all SKUs in a cost price update batch have the specified supplier linked.
 *
 * Fail-fast pre-flight check: if any SKU lacks the supplier, the entire batch is rejected.
 */
final readonly class SkuSupplierLinkValidator implements ValidatorInterface
{
    /**
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     * @param string $supplierName Batch-level supplier name to validate against
     * @param array<string, list<ProductSupplier>> $suppliersBySku
     */
    public function __construct(
        private array $commands,
        private string $supplierName,
        private array $suppliersBySku,
    ) {}

    public function validate(): SkuSupplierLinkResult
    {
        $supplierName = $this->supplierName;

        $unlinkedSkus = \array_values(
            \array_filter(
                \array_map(
                    static fn(UpdateCostPriceCommand $cmd): Sku => $cmd->sku,
                    $this->commands,
                ),
                fn(Sku $sku): bool => ! $this->hasSupplierLinked($sku, $supplierName),
            ),
        );

        return new SkuSupplierLinkResult($unlinkedSkus, $supplierName);
    }

    private function hasSupplierLinked(Sku $sku, string $supplierName): bool
    {
        $suppliers = $this->suppliersBySku[$sku->value] ?? [];

        return \array_any(
            $suppliers,
            static fn(ProductSupplier $s): bool => $s->supplierName === $supplierName,
        );
    }
}
