<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\Exceptions\QuickBooksMappingsUnresolvedException;
use App\Domain\Shared\ValueObjects\Deadline;
use Psr\Log\LoggerInterface;

final readonly class SyncOrdersByReferenceUseCase
{
    public function __construct(
        private OrderSyncSettingsInterface $settings,
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private QuickBooksTokenService $tokens,
        private QuickBooksRealmInterface $realm,
        private QuickBooksMappingRepositoryInterface $mappings,
        private SyncOrderChunkUseCase $syncChunk,
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
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws QuickBooksMappingsUnresolvedException
     * @throws UnexpectedApiResultException
     * @throws ValidationFailedException when the floor is unset or an order predates it
     */
    public function execute(array $references, bool $forceRetry, Deadline $deadline): OrderSyncResultsResult
    {
        $this->logger->info('Syncing QuickBooks orders by reference', ['references' => $references, 'force_retry' => $forceRetry]);

        $this->assertOnOrAfterFloor($references);
        $this->tokens->assertConnected();
        $realmId = $this->realm->realmId();
        $this->assertMappingsResolved($realmId);

        $chunk = new OrderSyncChunkDTO($realmId, $references, $forceRetry, OrderSyncTrigger::Manual, null);
        $results = $this->syncChunk->execute($this->tokens->accessToken(), $chunk, $deadline);

        $this->logger->info('Synced QuickBooks orders by reference', ['counts' => $results->counts()]);

        return $results;
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

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksMappingsUnresolvedException
     */
    private function assertMappingsResolved(string $realmId): void
    {
        $unresolved = $this->mappings->unresolvedKeys($realmId);
        if ($unresolved !== []) {
            throw new QuickBooksMappingsUnresolvedException(\array_map(static fn(MappingKey $key): string => $key->value, $unresolved));
        }
    }
}
