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
        return ['make', 'test-changed'];
    }

    protected function getTimeout(): int
    {
        return 180;
    }

    protected function getSuccessMessage(): string
    {
        return 'Changed-test step passed (Pest is skipped when no or over 100 test files changed). CI runs the full suite.';
    }

    protected function getFailureMessage(): string
    {
        return 'Tests failed! Fix the failing tests before pushing.';
    }
}
