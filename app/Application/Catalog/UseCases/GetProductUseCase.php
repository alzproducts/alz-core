<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Catalog\Queries\ProductDetailQueryParams;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Get a single product by external ID with conditional includes.
 *
 * @see ProductRepositoryInterface::findProductView()
 */
final readonly class GetProductUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException When no product matches the ID
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws RecordNotFoundException When product row not found in database
     */
    public function execute(ProductDetailQueryParams $query): GetProductResult
    {
        $this->logger->info('Getting product', [
            'product_id' => $query->productId->value,
            'includes' => $query->includes,
        ]);

        $product = $this->productRepository->findProductView($query);

        $this->logger->info('Got product', [
            'product_id' => $query->productId->value,
            'title' => $product->title,
        ]);

        return new GetProductResult(
            product: $product,
            includes: $query->includes,
        );
    }
}
