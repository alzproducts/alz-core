<?php

declare(strict_types=1);

namespace App\DevTools\GitHooks;

final class InfectionPrePushHook extends BaseProcessHook
{
    protected string $name = 'Infection Mutation Tests';

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        // Use composer script to centralize configuration
        return [
            'composer',
            'infection:strict',
        ];
    }

    protected function getTimeout(): int
    {
        return 600; // 10 minutes (mutation testing is slow in hook context)
    }

    protected function getSuccessMessage(): string
    {
        return 'Mutation tests passed! Your tests are catching code mutations.';
    }

    protected function getFailureMessage(): string
    {
        return 'Mutation tests failed! Some mutations escaped detection. Strengthen your test assertions before pushing.';
    }
}
