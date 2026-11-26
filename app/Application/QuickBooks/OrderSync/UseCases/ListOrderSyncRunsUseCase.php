<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncRunQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;
use Psr\Log\LoggerInterface;

final readonly class ListOrderSyncRunsUseCase
{
    public function __construct(
        private OrderSyncRunQueryRepositoryInterface $runs,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return PaginatedList<OrderSyncRunDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(PageRequest $page): PaginatedList
    {
        $this->logger->info('Listing QuickBooks order sync runs', ['page' => $page->page, 'per_page' => $page->perPage]);

        $result = $this->runs->paginate($page);

        $this->logger->info('Listed QuickBooks order sync runs', ['total' => $result->total, 'returned' => \count($result->items)]);

        return $result;
    }
}
