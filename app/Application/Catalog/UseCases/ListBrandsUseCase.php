<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Application\DTOs\PaginatedListDTO;
use App\Domain\Catalog\Brand\ValueObjects\BrandView;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
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
     * @param list<string> $includes Embed names to load
     *
     * @return PaginatedListDTO<BrandView>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    public function execute(int $perPage, int $page, array $includes = [], bool $includeInactive = false): PaginatedListDTO
    {
        $this->logger->info('Listing brands', [
            'page' => $page,
            'per_page' => $perPage,
            'includes' => $includes,
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
