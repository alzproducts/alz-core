<?php

declare(strict_types=1);

namespace App\Console\GitHooks;

class PestMutatePrePushHook extends BaseProcessHook
{
    protected string $name = 'Pest Mutation Tests';

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        return [
            './vendor/bin/pest',
            '--mutate',
            '--everything',
            '--covered-only',
            '--min=90',
        ];
    }

    protected function getTimeout(): int
    {
        return 300; // 5 minutes
    }

    protected function getSuccessMessage(): string
    {
        return 'Pest mutation tests passed! Your tests are catching code mutations.';
    }

    protected function getFailureMessage(): string
    {
        return 'Pest mutation tests failed! Some mutations escaped detection. Strengthen your test assertions before pushing.';
    }
}
