<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\Validators;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Result of Money equality validation.
 */
final readonly class MoneyEqualsResult implements DescribableValidationResultInterface
{
    use ThrowsOnValidationFailureTrait;

    public function __construct(
        private bool $equal,
        private Money $proposed,
        private Money $current,
    ) {}

    public function passed(): bool
    {
        return $this->equal;
    }

    public function failed(): bool
    {
        return ! $this->equal;
    }

    public function reason(): string
    {
        if ($this->passed()) {
            return '';
        }

        return \sprintf(
            'Money values differ: proposed £%s (%s) vs current £%s (%s)',
            \number_format($this->proposed->toGross(), 2),
            $this->proposed->taxType->value,
            \number_format($this->current->toGross(), 2),
            $this->current->taxType->value,
        );
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        if ($this->passed()) {
            return [];
        }

        return [
            'proposed_gross' => $this->proposed->toGross(),
            'proposed_tax_type' => $this->proposed->taxType->value,
            'current_gross' => $this->current->toGross(),
            'current_tax_type' => $this->current->taxType->value,
            'currency' => $this->proposed->currency,
        ];
    }
}
