<?php

declare(strict_types=1);

namespace App\Console\GitHooks;

use Closure;
use Exception;
use Igorsgm\GitHooks\Contracts\PrePushHook;
use Igorsgm\GitHooks\Git\Log;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class PHPInsightsPrePushHook implements PrePushHook
{
    protected string $name = 'PHP Insights';

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
        $this->info('Running PHP Insights...');

        $process = new Process([
            './vendor/bin/phpinsights',
            '--no-interaction',
            '--min-quality=90',
            '--min-complexity=85',
            '--min-architecture=90',
            '--min-style=95',
        ]);
        $process->setTimeout(180); // 3 minutes max
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('PHP Insights quality check failed!');
            $this->line($process->getOutput());

            throw new Exception('PHP Insights quality standards not met');
        }

        $this->info('✓ Code quality standards met!');

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
