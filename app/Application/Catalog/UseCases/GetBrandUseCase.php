<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Get a single brand by external ID with conditional includes.
 *
 * @see BrandRepositoryInterface::findBrandForApi()
 */
final readonly class GetBrandUseCase
{
    public function __construct(
        private BrandRepositoryInterface $brandRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $includes Embed names to load
     *
     * @throws ResourceNotFoundException When no brand matches the ID
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    public function execute(int $brandId, array $includes = []): GetBrandResult
    {
        $this->logger->info('Getting brand', [
            'brand_id' => $brandId,
            'includes' => $includes,
        ]);

        $brand = $this->brandRepository->findBrandForApi(
            IntId::from($brandId),
            $includes,
        );

        $this->logger->info('Got brand', [
            'brand_id' => $brandId,
            'title' => $brand->title,
        ]);

        return new GetBrandResult(
            brand: $brand,
            includes: $includes,
        );
    }
}
