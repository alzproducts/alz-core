<?php

declare(strict_types=1);

namespace App\DevTools\GitHooks;

final class InfectionPrePushHook extends BaseProcessHook
{
    public function getName(): string
    {
        return 'Infection Mutation Tests';
    }

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        // Use composer script to centralize configuration
        return [
            'composer',
            'infection:fast',
        ];
    }

    protected function getTimeout(): int
    {
        return 180; // 3 minutes (50s manual run + 40-60% hook overhead)
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
