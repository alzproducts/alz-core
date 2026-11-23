<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncClaimService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\ValueObjects\Deadline;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

final readonly class SyncOrderChunkUseCase
{
    public function __construct(
        private OrderSyncClaimService $claims,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(#[SensitiveParameter] ApiKeyToken $accessToken, OrderSyncChunkDTO $chunk, Deadline $deadline): OrderSyncResultsResult
    {
        $this->logger->info('Syncing QuickBooks order chunk', ['references' => $chunk->references]);

        $results = OrderSyncResultsResult::inRequestOrder($chunk->references, $this->claims->claim($chunk));

        $this->logger->info('Synced QuickBooks order chunk', ['counts' => $results->counts()]);

        return $results;
    }
}
