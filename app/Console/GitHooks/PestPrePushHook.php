<?php

declare(strict_types=1);

namespace App\Console\GitHooks;

class PestPrePushHook extends BaseProcessHook
{
    protected string $name = 'Pest Tests';

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        return ['./vendor/bin/pest', '--parallel'];
    }

    protected function getTimeout(): int
    {
        return 300; // 5 minutes
    }

    protected function getSuccessMessage(): string
    {
        return 'All tests passed!';
    }

    protected function getFailureMessage(): string
    {
        return 'Tests failed! Fix the failing tests before pushing.';
    }
}
