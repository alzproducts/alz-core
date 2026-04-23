<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;
use Override;

/**
 * Thrown when a domain validation check fails.
 *
 * A single exception class serving all validators — single and aggregate.
 * It is a dumb carrier: receives a pre-formatted reason string and pre-built
 * context array. It does not know or care whether its data came from one
 * validator or five.
 *
 * The reason() and context() methods mirror DescribableValidationResultInterface
 * for API consistency: $result->reason() and $exception->reason() work the same.
 *
 * @see DescribableValidationResultInterface
 * @see ThrowsOnValidationFailureTrait
 */
final class ValidationFailedException extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct($reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function context(): array
    {
        return $this->context;
    }
}
