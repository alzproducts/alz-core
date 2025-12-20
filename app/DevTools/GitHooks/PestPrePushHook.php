<?php

declare(strict_types=1);

namespace App\DevTools\GitHooks;

final class PestPrePushHook extends AbstractProcessHook
{
    public function getName(): string
    {
        return 'Pest Tests';
    }

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        return ['make', 'test-quick'];
    }

    protected function getTimeout(): int
    {
        return 60; // 1 minute (unit tests only, ~5s typical)
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
