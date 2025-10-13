<?php

declare(strict_types=1);

namespace App\Console\GitHooks;

class InfectionPrePushHook extends BaseProcessHook
{
    protected string $name = 'Infection Mutation Tests';

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        return [
            './vendor/bin/infection',
            '--min-msi=70',
            '--min-covered-msi=80',
            '--only-covered',
            '--show-mutations',
            '--threads=4',
        ];
    }

    protected function getTimeout(): int
    {
        return 300; // 5 minutes
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
