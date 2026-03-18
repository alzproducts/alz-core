<?php

declare(strict_types=1);

namespace App\Domain\Shared\Validation\Contracts;

use App\Domain\Exceptions\ValidationFailedException;

/**
 * The single interface for all validation results.
 *
 * Every validator returns an implementor of this interface. Two methods for
 * the boolean state (passed() and failed()) because callers read more naturally
 * with the method that matches their branch.
 *
 * reason() provides a consistent message authored once on the result class.
 * context() provides structured data for Sentry/logging.
 * orFail() converts a failed result into an exception — the strict consumption path.
 *
 * Note: reason() is for developer/ops observability (logs, Sentry, exception messages).
 * It is NOT user-facing. Presentation builds its own messages from context().
 */
interface DescribableValidationResultInterface
{
    public function passed(): bool;

    public function failed(): bool;

    /**
     * Human-readable failure reason for developer/ops observability.
     *
     * Defined once on the result class — not reconstructed by each UseCase.
     * This is NOT user-facing. Presentation builds its own messages from context().
     */
    public function reason(): string;

    /**
     * Structured context for logging and error tracking (e.g. Sentry).
     *
     * @return array<string, mixed>
     */
    public function context(): array;

    /**
     * Throw if the validation failed. No-op if passed.
     *
     * Implementation is provided by the ThrowsOnValidationFailure trait
     * and enforced by convention — do not implement manually.
     *
     * @throws ValidationFailedException
     */
    public function orFail(): void;
}
