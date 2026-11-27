<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;

interface OrderSyncRunQueryRepositoryInterface
{
    /**
     * Runs, newest request first.
     *
     * @return PaginatedList<OrderSyncRunDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function paginate(PageRequest $page): PaginatedList;

    /**
     * The run with this trigger requested last, or null when there is none.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function latestFor(OrderSyncTrigger $trigger): ?OrderSyncRunDTO;
}
