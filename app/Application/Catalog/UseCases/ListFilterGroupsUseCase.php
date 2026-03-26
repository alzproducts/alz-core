<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\FilterGroupRepositoryInterface;
use App\Application\DTOs\PaginatedListDTO;
use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * List filter groups with pagination.
 *
 * @see FilterGroupRepositoryInterface::paginate()
 */
final readonly class ListFilterGroupsUseCase
{
    public function __construct(
        private FilterGroupRepositoryInterface $filterGroupRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return PaginatedListDTO<FilterGroupDefinition>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(int $perPage, int $page): PaginatedListDTO
    {
        $this->logger->info('Listing filter groups', [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        $result = $this->filterGroupRepository->paginate($perPage, $page);

        $this->logger->info('Listed filter groups', [
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }
}
