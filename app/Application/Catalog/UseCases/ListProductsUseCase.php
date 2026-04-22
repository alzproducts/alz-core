<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\PaginatedList;
use Psr\Log\LoggerInterface;

/**
 * List active products with optional eager-loaded relations.
 *
 * @see ProductRepositoryInterface::paginate()
 */
final readonly class ListProductsUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return PaginatedList<ProductView>
     *
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(ProductListQueryParams $query): PaginatedList
    {
        $this->logger->info('Listing products', [
            'page' => $query->pagination->page,
            'per_page' => $query->pagination->perPage,
            'includes' => $query->includes,
            'sort' => $query->sortField?->value,
            'direction' => $query->sortDirection->value,
        ]);

        $result = $this->productRepository->paginate($query);

        $this->logger->info('Listed products', [
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }
}
