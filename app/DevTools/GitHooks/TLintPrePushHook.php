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
        // intentional: never a bare `vendor/bin/tlint`. Its walk enters excluded directories too,
        // so nested agent worktrees outlast the timeout.
        return ['make', 'tlint'];
    }

    protected function getTimeout(): int
    {
        return 90; // 90 seconds: `make tlint` took over 30s on a loaded machine
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
