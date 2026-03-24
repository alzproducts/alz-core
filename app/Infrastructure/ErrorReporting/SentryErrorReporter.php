<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorReporting;

use App\Application\Contracts\ErrorReporterInterface;

use function Sentry\captureException;

use Sentry\State\Scope;

use function Sentry\withScope;

use Throwable;

/**
 * Report exceptions to Sentry with structured context.
 */
final readonly class SentryErrorReporter implements ErrorReporterInterface
{
    public function report(Throwable $exception, array $context = []): void
    {
        withScope(static function (Scope $scope) use ($exception, $context): void {
            if ($context !== []) {
                $scope->setContext('extra', $context);
            }

            captureException($exception);
        });
    }
}
