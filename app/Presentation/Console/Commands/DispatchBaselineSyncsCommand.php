<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dispatch baseline definition sync jobs (custom fields + filter groups).
 *
 * Intended for deploy-time invocation via docker-entrypoint.sh (gated by
 * DISPATCH_BASELINE_SYNCS) so a fresh or updated deployment seeds current
 * definitions instead of waiting for the next hourly schedule. Both jobs are
 * ShouldBeUnique, so repeat runs while a sync is in flight are silent no-ops.
 */
final class DispatchBaselineSyncsCommand extends Command
{
    protected $signature = 'app:dispatch-baseline-syncs';

    protected $description = 'Dispatch baseline definition sync jobs (custom fields + filter groups)';

    public function handle(ShopwiredSyncDispatcherInterface $dispatcher): int
    {
        try {
            $this->info('Dispatching baseline sync jobs...');

            $dispatcher->dispatchCustomFieldsSync();
            $this->line('  Custom field definitions sync dispatched');

            $dispatcher->dispatchFilterGroupsSync();
            $this->line('  Filter groups sync dispatched');

            return self::SUCCESS;
        } catch (Throwable $e) { // @ignoreException - deploy-time dispatch: report failure via exit code, never crash the container
            $this->error('Failed to dispatch baseline sync jobs: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
