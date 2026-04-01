<?php

/** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */

declare(strict_types=1);

namespace App\DevTools\GitHooks;

use Closure;
use Igorsgm\GitHooks\Contracts\PreCommitHook;
use Igorsgm\GitHooks\Exceptions\HookFailException;
use Igorsgm\GitHooks\Git\ChangedFiles;
use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

abstract class AbstractPreCommitProcessHook implements PreCommitHook
{
    protected Command $command;

    abstract public function getName(): string;

    protected function getCommand(): Command
    {
        return $this->command;
    }

    public function setCommand(Command $command): void
    {
        $this->command = $command;
    }

    /**
     * @throws HookFailException When the hook command fails
     * @throws ProcessRuntimeException When process execution fails (command not found, etc.)
     */
    public function handle(ChangedFiles $files, Closure $next): mixed
    {
        $hookName = $this->getName();
        $this->command->info("Running {$hookName}...");

        $process = new Process($this->getProcessCommand());
        $process->setTimeout($this->getTimeout());
        $process->run();

        $this->ensureProcessSucceeded($process, $hookName);
        $this->command->info('✓ ' . $this->getSuccessMessage());

        return $next($files);
    }

    /**
     * @throws HookFailException When the hook command fails
     */
    private function ensureProcessSucceeded(Process $process, string $hookName): void
    {
        if ($process->isSuccessful()) {
            return;
        }

        $this->command->error($this->getFailureMessage());
        $this->command->line($process->getOutput());

        $errorOutput = $process->getErrorOutput();
        if ($errorOutput !== '') {
            $this->command->line($errorOutput);
        }

        throw new HookFailException($hookName . ' failed.');
    }

    /**
     * @return list<string>
     */
    abstract protected function getProcessCommand(): array;

    abstract protected function getTimeout(): int;

    abstract protected function getSuccessMessage(): string;

    abstract protected function getFailureMessage(): string;
}
