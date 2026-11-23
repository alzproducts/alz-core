<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\FailedOrderSyncDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncStateDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;

interface OrderSyncQueryRepositoryInterface
{
    /**
     * One company's stored rows keyed by reference; a reference without a row is omitted.
     *
     * @param list<int> $references
     *
     * @return array<int, OrderSyncStateDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function statesFor(string $realmId, array $references): array;

    /**
     * Failed rows of one company, newest attempt first.
     *
     * @return PaginatedList<FailedOrderSyncDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function paginateFailed(string $realmId, PageRequest $page): PaginatedList;
}
