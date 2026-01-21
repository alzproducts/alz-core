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
        return ['make', 'test'];
    }

    protected function getTimeout(): int
    {
        return 120; // 2 minutes (full test suite, ~8s typical with parallel)
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
