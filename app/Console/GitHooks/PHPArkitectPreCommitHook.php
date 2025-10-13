<?php

declare(strict_types=1);

namespace App\Console\GitHooks;

class PHPArkitectPreCommitHook extends BasePreCommitProcessHook
{
    protected string $name = 'PHPArkitect';

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        return [
            './vendor/bin/phparkitect',
            'check',
        ];
    }

    protected function getTimeout(): int
    {
        return 30; // 30 seconds (overkill for 0.1s, but safe)
    }

    protected function getSuccessMessage(): string
    {
        return 'Architecture boundaries preserved!';
    }

    protected function getFailureMessage(): string
    {
        return 'PHPArkitect architecture check failed!';
    }
}
