<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncPreflightService;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\QuickBooks\Exceptions\QuickBooksMappingsUnresolvedException;
use Psr\Log\LoggerInterface;

/**
 * Accepts orders to sync by reference. Everything a caller can act on is checked here, before anything is queued.
 */
final readonly class SyncOrdersByReferenceUseCase
{
    public function __construct(
        private OrderSyncSettingsInterface $settings,
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private OrderSyncPreflightService $preflight,
        private QuickBooksTokenService $tokens,
        private OrderSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param non-empty-list<int> $references
     *
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws InvalidConfigurationException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     * @throws QuickBooksMappingsUnresolvedException
     * @throws ValidationFailedException when the floor is unset or an order predates it
     */
    public function execute(array $references, bool $forceRetry, string $runId): void
    {
        $this->logger->info('Requesting QuickBooks order sync by reference', [
            'run_id' => $runId,
            'references' => $references,
            'force_retry' => $forceRetry,
        ]);

        $this->assertOnOrAfterFloor($references);
        $realmId = $this->preflight->realmReadyToSync();
        // intentional: the token is unused; this refuses now what the chunk would fail on.
        $this->tokens->accessToken();

        $chunk = new OrderSyncChunkDTO($realmId, $references, $forceRetry, OrderSyncTrigger::Manual, $runId);
        $this->dispatcher->dispatchChunk(new OrderChunkRunDTO($chunk));

        $this->logger->info('Requested QuickBooks order sync by reference', ['run_id' => $runId]);
    }

    /**
     * @param non-empty-list<int> $references
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidConfigurationException
     * @throws ValidationFailedException
     */
    private function assertOnOrAfterFloor(array $references): void
    {
        $floor = $this->settings->backfillFloor();
        if ($floor === null) {
            throw self::refusedReferences('The QuickBooks backfill floor date is not set.');
        }

        $floorStart = OrderSyncCalendarResolver::startOfDate($floor);
        $rows = $this->sourceOrders->findByReferences($references);
        if (\array_any($rows, static fn(SourceOrderRowDTO $row): bool => $row->createdAt < $floorStart)) {
            throw self::refusedReferences('An order was placed before the QuickBooks backfill floor date.');
        }
    }

    private static function refusedReferences(string $reason): ValidationFailedException
    {
        return new ValidationFailedException($reason, ['references' => [$reason]]);
    }
}
