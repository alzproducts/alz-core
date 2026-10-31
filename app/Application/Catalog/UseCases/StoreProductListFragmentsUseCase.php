<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Application\Contracts\Catalog\ProductListFragmentStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Write back the fragments a list request rendered, so later requests can reuse them.
 */
final readonly class StoreProductListFragmentsUseCase
{
    public function __construct(
        private ProductListFragmentStoreInterface $store,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<ProductListFragmentDTO> $fragments
     */
    public function execute(array $fragments): void
    {
        $this->logger->info('Storing product list fragments', ['count' => \count($fragments)]);

        if ($fragments === []) {
            return;
        }

        $this->store->storeMany($fragments);

        $this->logger->info('Stored product list fragments');
    }
}
