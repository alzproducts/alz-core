<?php

declare(strict_types=1);

namespace App\Domain\Shared\Validation\Concerns;

use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Trait for aggregate validation results.
 *
 * Includes ThrowsOnValidationFailureTrait internally via trait composition, so
 * aggregate result classes only use this single trait. Provides default
 * implementations of passed(), failed(), reason(), and context() that loop
 * through child results, and inherits orFail() from ThrowsOnValidationFailureTrait.
 *
 * The childResults() method is abstract — aggregate result classes must implement it.
 * The string keys in the returned array become the keys in the aggregated context() output.
 */
trait AggregatesChildResultsTrait
{
    use ThrowsOnValidationFailureTrait;

    /** @return array<string, DescribableValidationResultInterface> */
    abstract protected function childResults(): array;

    public function passed(): bool
    {
        return \array_all($this->childResults(), static fn($result) => ! $result->failed());
    }

    public function failed(): bool
    {
        return ! $this->passed();
    }

    public function reason(): string
    {
        $reasons = [];

        foreach ($this->childResults() as $result) {
            if ($result->failed()) {
                $reasons[] = $result->reason();
            }
        }

        return \implode('; ', $reasons);
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        $context = [];

        foreach ($this->childResults() as $name => $result) {
            if ($result->failed()) {
                $context[$name] = $result->context();
            }
        }

        return $context;
    }
}
