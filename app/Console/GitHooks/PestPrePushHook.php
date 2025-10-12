<?php

declare(strict_types=1);

namespace App\Console\GitHooks;

use Closure;
use Exception;
use Igorsgm\GitHooks\Contracts\PrePushHook;
use Igorsgm\GitHooks\Git\Log;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PestPrePushHook implements PrePushHook
{
    protected string $name = 'Pest Tests';

    /** @phpstan-ignore-next-line shipmonk.publicPropertyNotReadonly - Required by Hook interface for dependency injection */
    public Command $command;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setCommand(Command $command): void
    {
        $this->command = $command;
    }

    public function handle(Log $log, Closure $next): mixed
    {
        $this->info('Running Pest tests...');

        $process = new Process(['./vendor/bin/pest', '--parallel']);
        $process->setTimeout(300); // 5 minutes max
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Tests failed! Fix the failing tests before pushing.');
            $this->line($process->getOutput());

            throw new Exception('Pest tests failed');
        }

        $this->info('✓ All tests passed!');

        return $next($log);
    }

    protected function info(string $message): void
    {
        echo "\033[32m{$message}\033[0m\n";
    }

    protected function error(string $message): void
    {
        echo "\033[31m{$message}\033[0m\n";
    }

    protected function line(string $message): void
    {
        echo "{$message}\n";
    }
}
