<?php

declare(strict_types=1);

namespace App\Console\GitHooks;

final class PHPInsightsPrePushHook extends BaseProcessHook
{
    protected string $name = 'PHP Insights';

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        return [
            './vendor/bin/phpinsights',
            '--no-interaction',
            '--min-quality=90',
            '--min-complexity=85',
            '--min-architecture=90',
            '--min-style=95',
        ];
    }

    protected function getTimeout(): int
    {
        return 180; // 3 minutes
    }

    protected function getSuccessMessage(): string
    {
        return 'Code quality standards met!';
    }

    protected function getFailureMessage(): string
    {
        return 'PHP Insights quality check failed!';
    }
}
