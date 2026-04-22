<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\PaginatedList;

/**
 * Repository for ShopWired filter group definition persistence.
 *
 * @extends RepositoryWriteInterface<FilterGroupDefinition>
 */
interface FilterGroupRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Get a filter group definition by its optionNo, or throw if not found.
     *
     * This is the primary lookup method used when resolving product filter data.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws ResourceNotFoundException When no filter group exists with the given optionNo
     */
    public function getByOptionNo(int $optionNo): FilterGroupDefinition;

    /**
     * Get all filter group definitions, ordered by sort_order.
     *
     * @return list<FilterGroupDefinition>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findAll(): array;

    /**
     * Paginate filter groups ordered by sort_order.
     *
     * @return PaginatedList<FilterGroupDefinition>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function paginate(int $perPage, int $page): PaginatedList;
}
