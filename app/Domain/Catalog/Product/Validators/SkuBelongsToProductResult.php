<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Result of SKU-belongs-to-product validation.
 *
 * Carries the list of missing SKUs and provides the standard validation
 * result API (passed/failed/reason/context/orFail).
 */
final readonly class SkuBelongsToProductResult implements DescribableValidationResultInterface
{
    use ThrowsOnValidationFailureTrait;

    /**
     * @param  list<Sku>  $missingSkus
     */
    public function __construct(
        private array $missingSkus = [],
    ) {}

    public function passed(): bool
    {
        return $this->missingSkus === [];
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

        $count = \count($this->missingSkus);

        return "SKU validation failed: {$count} SKU(s) do not belong to the product";
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        if ($this->passed()) {
            return [];
        }

        return [
            'missing_skus' => \array_map(
                static fn(Sku $sku): string => $sku->value,
                $this->missingSkus,
            ),
        ];
    }

    /**
     * Domain-specific accessor for callers that need the missing SKU list.
     *
     * @return list<Sku>
     */
    public function missingSkus(): array
    {
        return $this->missingSkus;
    }
}
