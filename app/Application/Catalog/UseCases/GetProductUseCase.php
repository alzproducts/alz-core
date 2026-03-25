<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Get a single product by external ID with conditional includes.
 *
 * @see ProductRepositoryInterface::findProductForApi()
 */
final readonly class GetProductUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $includes Embed names to load
     *
     * @throws ResourceNotFoundException When no product matches the ID
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(int $productId, array $includes = []): GetProductResult
    {
        $this->logger->info('Getting product', [
            'product_id' => $productId,
            'includes' => $includes,
        ]);

        $product = $this->productRepository->findProductForApi(
            IntId::from($productId),
            $includes,
        );

        $this->logger->info('Got product', [
            'product_id' => $productId,
            'title' => $product->title,
        ]);

        return new GetProductResult(
            product: $product,
            includes: $includes,
        );
    }
}
