<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
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
     * @param list<BrandInclude> $includes Embed names to load
     *
     * @throws ResourceNotFoundException When no brand matches the ID
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws RecordNotFoundException When brand row not found in database
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function execute(int $brandId, array $includes = []): GetBrandResult
    {
        $this->logStart($brandId, $includes);

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

    /**
     * @param list<BrandInclude> $includes
     */
    private function logStart(int $brandId, array $includes): void
    {
        $this->logger->info('Getting brand', [
            'brand_id' => $brandId,
            'includes' => \array_map(static fn(BrandInclude $i): string => $i->value, $includes),
        ]);
    }
}
