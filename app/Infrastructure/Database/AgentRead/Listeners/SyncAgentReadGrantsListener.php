<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\AgentRead\Listeners;

use App\Infrastructure\Database\AgentRead\AgentReadAllowlist;
use App\Infrastructure\Database\AgentRead\AgentReadGrantSync;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Log\Context\Repository as Context;

/**
 * Re-applies the Read allowlist after every forward migrate, a run with nothing pending included, unless the migrate
 * only pretends. NoPendingMigrations carries no options, so a pretend run is noted when the migrate command starts.
 */
final readonly class SyncAgentReadGrantsListener
{
    private const string PRETENDING = 'agent_read.migrate_pretending';

    public function __construct(
        private AgentReadGrantSync $sync,
        private Context $context,
    ) {}

    public function handle(CommandStarting|MigrationsEnded|NoPendingMigrations $event): void
    {
        if ($event instanceof CommandStarting) {
            $this->notePretend($event);

            return;
        }

        if ($event->method !== 'up' || $this->pretending($event)) {
            return;
        }

        $this->sync->sync(AgentReadAllowlist::committed());
    }

    private function notePretend(CommandStarting $event): void
    {
        if ($event->command !== 'migrate') {
            return;
        }

        $event->input->hasParameterOption('--pretend', true)
            ? $this->context->addHidden(self::PRETENDING, true)
            : $this->context->forgetHidden(self::PRETENDING);
    }

    private function pretending(MigrationsEnded|NoPendingMigrations $event): bool
    {
        return $event instanceof MigrationsEnded
            ? ($event->options['pretend'] ?? false) === true
            : $this->context->getHidden(self::PRETENDING) === true;
    }
}
