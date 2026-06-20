<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Catalog;

use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Illuminate\Console\Command;

final class RefreshProductsViewCommand extends Command
{
    protected $signature = 'catalog:refresh-products-view';

    protected $description = 'Refresh the catalog.products_view materialized view';

    public function handle(ProductViewQueryRepositoryInterface $repository): int
    {
        $start = \microtime(true);

        try {
            $repository->refreshMaterializedView();
        } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e) {
            $this->error('Failed to refresh materialized view: ' . $e->getMessage());
            $this->line('  Check: database connectivity and that the materialized view exists.');

            return self::FAILURE;
        }

        $elapsed = \round(\microtime(true) - $start, 2);
        $this->info("Refreshed catalog.products_view in {$elapsed}s.");

        return self::SUCCESS;
    }
}
