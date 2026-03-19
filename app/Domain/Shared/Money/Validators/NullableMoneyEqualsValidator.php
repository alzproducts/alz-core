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

        // Both null = equal
        if ($this->proposed === null && $this->current === null) {
            return new NullableMoneyEqualsResult(true, $proposedGross, $currentGross);
        }

        // One null, one not = different
        if ($this->proposed === null || $this->current === null) {
            return new NullableMoneyEqualsResult(false, $proposedGross, $currentGross);
        }

        // Both non-null — delegate to MoneyEqualsValidator
        $equal = (new MoneyEqualsValidator($this->proposed, $this->current))
            ->validate()
            ->passed();

        return new NullableMoneyEqualsResult($equal, $proposedGross, $currentGross);
    }
}
