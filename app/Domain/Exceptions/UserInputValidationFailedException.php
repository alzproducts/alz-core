<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use App\Domain\Exceptions\Contracts\UserInputValidationExceptionInterface;

/**
 * Validation failure caused by user input (not an application fault).
 *
 * Mirrors ValidationFailedException's shape (reason + context) but extends
 * DomainException directly to satisfy the forbiddenExtendOfNonAbstractClass rule.
 * Implements UserInputValidationExceptionInterface marker to exclude from Sentry.
 *
 * Mapped to HTTP 422 by InternalApiExceptionMapper.
 */
final class UserInputValidationFailedException extends DomainException implements UserInputValidationExceptionInterface
{
    /**
     * @param array<string, mixed> $context
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
    public function context(): array
    {
        return $this->context;
    }
}
