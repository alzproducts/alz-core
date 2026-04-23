<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\Validators;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\Shared\Validation\Contracts\ValidatorInterface;

/**
 * Equality check on two nullable Money values.
 */
final readonly class NullableMoneyEqualsValidator implements ValidatorInterface
{
    public function __construct(
        private ?Money $proposed,
        private ?Money $current,
    ) {}

    public function validate(): NullableMoneyEqualsResult
    {
        $proposedGross = $this->proposed?->toGross();
        $currentGross = $this->current?->toGross();

        return new NullableMoneyEqualsResult($this->resolveEquality(), $proposedGross, $currentGross);
    }

    private function resolveEquality(): bool
    {
        if ($this->proposed === null && $this->current === null) {
            return true;
        }

        if ($this->proposed === null || $this->current === null) {
            return false;
        }

        return (new MoneyEqualsValidator($this->proposed, $this->current))
            ->validate()
            ->passed();
    }
}
