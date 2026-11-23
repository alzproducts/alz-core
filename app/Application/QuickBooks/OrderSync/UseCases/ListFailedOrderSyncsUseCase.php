<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\DTOs\FailedOrderSyncDTO;
use App\Application\QuickBooks\OrderSync\Enums\FailedListStatus;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Shared\Pagination\ValueObjects\PageRequest;
use App\Domain\ValueObjects\PaginatedList;
use Psr\Log\LoggerInterface;

final readonly class ListFailedOrderSyncsUseCase
{
    public function __construct(
        private QuickBooksTokenService $tokens,
        private QuickBooksRealmInterface $realm,
        private OrderSyncQueryRepositoryInterface $orderSyncs,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return PaginatedList<FailedOrderSyncDTO>
     *
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     */
    public function execute(FailedListStatus $status, PageRequest $page): PaginatedList
    {
        $this->logger->info('Listing failed QuickBooks order syncs', [
            'status' => $status->value,
            'page' => $page->page,
            'per_page' => $page->perPage,
        ]);

        $this->tokens->assertConnected();
        $this->tokens->accessToken();

        $result = $this->listRows($status, $page);

        $this->logger->info('Listed failed QuickBooks order syncs', [
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }

    /**
     * @return PaginatedList<FailedOrderSyncDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function listRows(FailedListStatus $status, PageRequest $page): PaginatedList
    {
        if ($status === FailedListStatus::Failed) {
            return $this->orderSyncs->paginateFailed($this->realm->realmId(), $page);
        }

        /** @var list<FailedOrderSyncDTO> $none */
        $none = [];

        return PaginatedList::fromPage($none, 0, $page->perPage, $page->page);
    }
}
