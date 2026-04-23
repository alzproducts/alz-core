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
        return 60; // 60 seconds (typically ~30s after #[Override] annotation sweep)
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
