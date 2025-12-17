<?php

declare(strict_types=1);

namespace App\DevTools\GitHooks;

final class PHPInsightsPrePushHook extends BaseProcessHook
{
    public function getName(): string
    {
        return 'PHP Insights';
    }

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        return ['composer', 'insights'];
    }

    protected function getTimeout(): int
    {
        return 180; // 3 minutes
    }

    protected function getSuccessMessage(): string
    {
        return 'Code quality standards met!';
    }

    protected function getFailureMessage(): string
    {
        return 'PHP Insights quality check failed!';
    }
}
