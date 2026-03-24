<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use Throwable;

/**
 * Report exceptions to an error tracking service (e.g., Sentry).
 *
 * Used for non-fatal errors that should alert operators but not crash the pipeline.
 */
interface ErrorReporterInterface
{
    /**
     * Report an exception with optional structured context.
     *
     * @param array<string, mixed> $context
     */
    public function report(Throwable $exception, array $context = []): void;
}
