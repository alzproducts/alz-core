<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Jobs\Inventory\UpdateSkuJob;
use App\Domain\Inventory\Commands\UpdateSkuCommand;

/**
 * Process SKU updates across Linnworks and ShopWired.
 *
 * Each command is queued as a separate job, serialized via ShouldBeUnique
 * to prevent race conditions on concurrent SKU updates.
 */
final readonly class ProcessSkuUpdatesUseCase
{
    /**
     * @param list<UpdateSkuCommand> $commands
     *
     * @return int Number of jobs dispatched
     */
    public function execute(array $commands): int
    {
        foreach ($commands as $command) {
            UpdateSkuJob::dispatch($command);
        }

        return \count($commands);
    }
}
