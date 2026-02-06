<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Jobs\Shopwired\SetProductFreeDeliveryJob;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;

/**
 * Update free delivery designation on ShopWired products.
 *
 * Splits a batch of commands into chunks and queues each chunk
 * as a separate job. This prevents single jobs from becoming
 * too large and timing out.
 */
final readonly class UpdateProductFreeDeliveryUseCase
{
    private const int CHUNK_SIZE = 25;

    /**
     * @param list<SetFreeDeliveryCommand> $commands
     *
     * @return int Number of jobs dispatched
     */
    public function execute(array $commands): int
    {
        if ($commands === []) {
            return 0;
        }

        /** @var list<list<SetFreeDeliveryCommand>> $chunks */
        $chunks = \array_chunk($commands, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            SetProductFreeDeliveryJob::dispatch($chunk);
        }

        return \count($chunks);
    }
}
