<?php

declare(strict_types=1);

namespace App\Domain\Shared\Validation\Concerns;

use App\Domain\Exceptions\ValidationFailedException;

/**
 * Mandated implementation of orFail() for all validation results.
 *
 * Every class implementing DescribableValidationResultInterface must use this
 * trait. No class may provide its own orFail() — this prevents bespoke exception
 * behaviour from drifting across the codebase.
 *
 * The abstract method declarations serve two purposes: PHP will throw a fatal
 * error if a class uses this trait without implementing them, and they
 * self-document the trait's dependencies.
 */
trait ThrowsOnValidationFailureTrait
{
    abstract public function failed(): bool;

    abstract public function reason(): string;

    /** @return array<string, mixed> */
    abstract public function context(): array;

    /** @throws ValidationFailedException */
    public function orFail(): void
    {
        if ($this->failed()) {
            throw new ValidationFailedException(
                reason: $this->reason(),
                context: $this->context(),
            );
        }
    }
}
