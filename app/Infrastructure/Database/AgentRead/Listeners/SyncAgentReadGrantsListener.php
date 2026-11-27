<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\AgentRead\Listeners;

use App\Infrastructure\Database\AgentRead\AgentReadAllowlist;
use App\Infrastructure\Database\AgentRead\AgentReadGrantSync;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;

/**
 * Re-applies the Read allowlist after every forward migrate, a run with nothing pending included.
 */
final readonly class SyncAgentReadGrantsListener
{
    public function __construct(private AgentReadGrantSync $sync) {}

    public function handle(MigrationsEnded|NoPendingMigrations $event): void
    {
        if ($event->method !== 'up') {
            return;
        }

        if ($event instanceof MigrationsEnded && ($event->options['pretend'] ?? false) === true) {
            return;
        }

        $this->sync->sync(AgentReadAllowlist::committed());
    }
}
