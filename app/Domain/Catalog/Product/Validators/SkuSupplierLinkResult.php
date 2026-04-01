<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Result of SKU-supplier link validation.
 *
 * Carries the list of SKUs that do not have the specified supplier linked.
 */
final readonly class SkuSupplierLinkResult implements DescribableValidationResultInterface
{
    use ThrowsOnValidationFailureTrait;

    /**
     * @param list<Sku> $unlinkedSkus SKUs missing the specified supplier
     */
    public function __construct(
        private array $unlinkedSkus,
        private string $supplierName,
    ) {}

    public function passed(): bool
    {
        return $this->unlinkedSkus === [];
    }

    public function failed(): bool
    {
        return ! $this->passed();
    }

    public function reason(): string
    {
        if ($this->passed()) {
            return '';
        }

        $count = \count($this->unlinkedSkus);

        return "Supplier link validation failed: {$count} SKU(s) do not have supplier '{$this->supplierName}' linked";
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        if ($this->passed()) {
            return [];
        }

        return [
            'unlinked_skus' => \array_map(
                static fn(Sku $sku): string => $sku->value,
                $this->unlinkedSkus,
            ),
            'supplier_name' => $this->supplierName,
        ];
    }

    /**
     * @return list<Sku>
     */
    public function unlinkedSkus(): array
    {
        return $this->unlinkedSkus;
    }
}
