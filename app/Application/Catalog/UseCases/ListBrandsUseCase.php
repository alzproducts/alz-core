<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Catalog\Brand\Enums\BrandInclude;
use App\Domain\Catalog\Brand\ValueObjects\BrandView;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\PaginatedList;
use Psr\Log\LoggerInterface;

/**
 * List brands with optional includes and active filtering.
 *
 * @see BrandRepositoryInterface::paginate()
 */
final readonly class ListBrandsUseCase
{
    public function __construct(
        private BrandRepositoryInterface $brandRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<BrandInclude> $includes Embed names to load
     *
     * @return PaginatedList<BrandView>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function execute(int $perPage, int $page, array $includes = [], bool $includeInactive = false): PaginatedList
    {
        $this->logger->info('Listing brands', [
            'page' => $page,
            'per_page' => $perPage,
            'includes' => \array_map(static fn(BrandInclude $i): string => $i->value, $includes),
            'include_inactive' => $includeInactive,
        ]);

        $result = $this->brandRepository->paginate($perPage, $page, $includes, $includeInactive);

        $this->logger->info('Listed brands', [
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }
}
