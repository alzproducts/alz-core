<?php

declare(strict_types=1);

namespace App\DevTools\GitHooks;

final class TLintPrePushHook extends AbstractProcessHook
{
    public function getName(): string
    {
        return 'TLint';
    }

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        // Full scan - checks entire codebase including tests, config, bootstrap
        return ['vendor/bin/tlint'];
    }

    protected function getTimeout(): int
    {
        return 180; // 180 seconds: a full-repo scan exceeded 60s on a loaded machine
    }

    protected function getSuccessMessage(): string
    {
        return 'Laravel conventions check passed!';
    }

    protected function getFailureMessage(): string
    {
        return 'TLint Laravel conventions check failed!';
    }
}
