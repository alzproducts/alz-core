<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\Validators;

use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Result of nullable Money equality validation.
 */
final readonly class NullableMoneyEqualsResult implements DescribableValidationResultInterface
{
    use ThrowsOnValidationFailureTrait;

    public function __construct(
        private bool $equal,
        private ?float $proposedGross,
        private ?float $currentGross,
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
            'Nullable Money values differ: proposed %s vs current %s',
            $this->proposedGross !== null ? \sprintf('£%s', \number_format($this->proposedGross, 2)) : 'null',
            $this->currentGross !== null ? \sprintf('£%s', \number_format($this->currentGross, 2)) : 'null',
        );
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        if ($this->passed()) {
            return [];
        }

        return [
            'proposed_gross' => $this->proposedGross,
            'current_gross' => $this->currentGross,
        ];
    }
}
