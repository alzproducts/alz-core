<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\Validators;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\Shared\Validation\Contracts\ValidatorInterface;

/**
 * Full equality check on two Money values — amount, currency, and taxType.
 */
final readonly class MoneyEqualsValidator implements ValidatorInterface
{
    public function __construct(
        private Money $proposed,
        private Money $current,
    ) {}

    public function validate(): MoneyEqualsResult
    {
        $equal = $this->proposed->amountEquals($this->current)
            && $this->proposed->taxType === $this->current->taxType;

        return new MoneyEqualsResult($equal, $this->proposed, $this->current);
    }
}
