<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\DTOs\PaginatedListDTO;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
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
     * @param list<string> $includes Relation names to eager-load (e.g., 'variations')
     *
     * @return PaginatedListDTO<Product>
     *
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(int $perPage, int $page, array $includes = []): PaginatedListDTO
    {
        $this->logger->info('Listing products', [
            'page' => $page,
            'per_page' => $perPage,
            'includes' => $includes,
        ]);

        $result = $this->productRepository->paginate($perPage, $page, $includes);

        $this->logger->info('Listed products', [
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }
}
