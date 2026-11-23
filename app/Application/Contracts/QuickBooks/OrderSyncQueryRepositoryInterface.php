<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\FailedOrderSyncDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;

interface OrderSyncQueryRepositoryInterface
{
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
