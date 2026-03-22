<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;

/**
 * Dispatch individual free delivery update jobs for each command.
 *
 * Replaces the old chunked batch dispatch — each command gets its own
 * single-item job, letting Laravel's retry system handle failures natively.
 */
final readonly class DispatchProductFreeDeliveryJobsUseCase
{
    public function __construct(
        private ShopwiredSyncDispatcherInterface $dispatcher,
    ) {}

    /**
     * @param list<SetFreeDeliveryCommand> $commands
     */
    public function execute(array $commands): void
    {
        foreach ($commands as $command) {
            $this->dispatcher->dispatchFreeDeliveryUpdate($command);
        }
    }
}
