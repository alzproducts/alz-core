<?php

declare(strict_types=1);

namespace App\Presentation\Console\Traits;

trait RequiresLocalQuickBooksSandboxTrait
{
    protected static function isLocalQuickBooksSandbox(): bool
    {
        return \config('app.env') === 'local' && \config('quickbooks.environment') === 'sandbox';
    }

    /** stdout carries only the command's payload, so a pipe never receives an error message. */
    protected function failOnStderr(string $error, string $check): int
    {
        $stderr = $this->output->getErrorStyle();
        $stderr->writeln($error);
        $stderr->writeln('  Check: ' . $check);

        return self::FAILURE;
    }
}
