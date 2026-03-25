<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\Validators;

use App\Domain\Shared\Validation\Concerns\AggregatesChildResultsTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Aggregate result for multiple VAT round-trip checks.
 *
 * Combines child results: failed() if any child failed, context() nests
 * each failed child's context under its key (e.g. "ABC-123.price").
 *
 * @see AggregatesChildResultsTrait
 */
final readonly class VatRoundTripAggregateResult implements DescribableValidationResultInterface
{
    use AggregatesChildResultsTrait;

    /** @param array<string, VatRoundTripResult> $results Keyed by "{sku}.{field}" */
    public function __construct(
        private array $results,
    ) {}

    /** @return array<string, VatRoundTripResult> */
    protected function childResults(): array
    {
        return $this->results;
    }
}
