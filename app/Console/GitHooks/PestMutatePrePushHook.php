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
        // Use composer script to centralize configuration
        return [
            'composer',
            'pest:mutate',
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
