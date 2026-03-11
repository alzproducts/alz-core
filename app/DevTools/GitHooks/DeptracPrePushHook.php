<?php

declare(strict_types=1);

namespace App\DevTools\GitHooks;

final class DeptracPrePushHook extends AbstractProcessHook
{
    public function getName(): string
    {
        return 'Deptrac';
    }

    /**
     * @return list<string>
     */
    protected function getProcessCommand(): array
    {
        return ['make', 'deptrac'];
    }

    protected function getTimeout(): int
    {
        return 30;
    }

    protected function getSuccessMessage(): string
    {
        return 'Layer dependency analysis passed!';
    }

    protected function getFailureMessage(): string
    {
        return 'Deptrac layer dependency violations found!';
    }
}
