<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRunQueryRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\CatchUpCandidateWindowDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunCountsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncRunStatus;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use App\Application\QuickBooks\OrderSync\Results\CatchUpOrderSyncResult;
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
use App\Domain\QuickBooks\Exceptions\QuickBooksMappingsUnresolvedException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One hourly catch-up run. A refusal or skip writes nothing; once the run row exists every exception is thrown, so
 * the job's failure handler ends the run rather than leaving it open to block later runs.
 */
final readonly class RunCatchUpOrderSyncUseCase
{
    private const string SCHEDULE_FROM_KEY = 'QUICKBOOKS_ORDER_SYNC_SCHEDULE_FROM';

    public function __construct(
        private OrderSyncSettingsInterface $settings,
        private OrderSyncRunQueryRepositoryInterface $runHistory,
        private OrderSyncPreflightService $preflight,
        private QuickBooksTokenService $tokens,
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private OrderSyncRunRepositoryInterface $runs,
        private OrderSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     * @throws Throwable what queueing a chunk threw
     */
    public function execute(string $runId, DateTimeImmutable $now): CatchUpOrderSyncResult
    {
        $this->logger->info('Running QuickBooks order catch-up sync', ['run_id' => $runId]);

        $start = $this->startDate();
        if ($start instanceof CatchUpOrderSyncResult) {
            return $start;
        }
        if ($this->previousRunOpen($now)) {
            return $this->skip(CatchUpOrderSyncResult::SKIPPED_PREVIOUS_RUN_OPEN);
        }
        $realmId = $this->readyRealm();
        if ($realmId instanceof CatchUpOrderSyncResult) {
            return $realmId;
        }

        $candidates = $this->sourceOrders->catchUpCandidates(new CatchUpCandidateWindowDTO($realmId, $start, self::hoursBefore($now, 1), self::hoursBefore($now, 48)));

        return $candidates === [] ? $this->skip(CatchUpOrderSyncResult::SKIPPED_NO_CANDIDATES) : $this->queue($runId, $realmId, $candidates, $now);
    }

    private static function hoursBefore(DateTimeImmutable $now, int $hours): DateTimeImmutable
    {
        return $now->setTimestamp($now->getTimestamp() - $hours * 3600);
    }

    private function startDate(): DateTimeImmutable|CatchUpOrderSyncResult
    {
        try {
            $scheduleFrom = $this->settings->scheduleFrom();
            if ($scheduleFrom === null) {
                return $this->skip(CatchUpOrderSyncResult::SKIPPED_SWITCHED_OFF);
            }
            $floor = $this->settings->backfillFloor();
        } catch (InvalidConfigurationException $e) {
            return $this->refuse($e->configKey === self::SCHEDULE_FROM_KEY
                ? CatchUpOrderSyncResult::REFUSED_SCHEDULE_FROM_MALFORMED
                : CatchUpOrderSyncResult::REFUSED_FLOOR_MALFORMED);
        }

        return $floor === null
            ? $this->refuse(CatchUpOrderSyncResult::REFUSED_FLOOR_UNSET)
            : OrderSyncCalendarResolver::startOfDate(\max($floor, $scheduleFrom));
    }

    /**
     * A run requested 24 hours ago or more stops blocking, so a lost job cannot halt the sync for longer.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function previousRunOpen(DateTimeImmutable $now): bool
    {
        $latest = $this->runHistory->latestFor(OrderSyncTrigger::Schedule);

        return $latest !== null
            && \in_array($latest->status, [OrderSyncRunStatus::Queued, OrderSyncRunStatus::Running], true)
            && $latest->requestedAt > self::hoursBefore($now, 24);
    }

    /**
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     */
    private function readyRealm(): string|CatchUpOrderSyncResult
    {
        try {
            $realmId = $this->preflight->realmReadyToSync();
        } catch (AuthenticationExpiredException) {
            return $this->refuse(CatchUpOrderSyncResult::REFUSED_NO_CONNECTION);
        } catch (QuickBooksMappingsUnresolvedException $e) {
            return $this->refuse(CatchUpOrderSyncResult::REFUSED_MAPPINGS_UNRESOLVED, ['missing_keys' => $e->missingKeys]);
        }

        try {
            // intentional: the token is unused; this refuses now what every chunk would fail on.
            $this->tokens->accessToken();
        } catch (AuthenticationExpiredException) {
            return $this->refuse(CatchUpOrderSyncResult::REFUSED_TOKEN_REJECTED);
        }

        return $realmId;
    }

    /**
     * The job holds one unit of the run's open-job tally until every chunk is queued, so an early chunk cannot end
     * the run mid-dispatch.
     *
     * @param non-empty-list<int> $candidates
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws Throwable what queueing a chunk threw
     */
    private function queue(string $runId, string $realmId, array $candidates, DateTimeImmutable $now): CatchUpOrderSyncResult
    {
        $this->runs->record(new RequestedOrderSyncRunDTO($runId, OrderSyncTrigger::Schedule, false, null, null, \count($candidates), $now));
        $this->runs->addOpenJob($runId);
        $chunks = \array_chunk($candidates, OrderSyncChunkDTO::MAX_REFERENCES);
        foreach ($chunks as $chunk) {
            $this->dispatcher->dispatchChunk(new OrderChunkRunDTO(
                new OrderSyncChunkDTO($realmId, $chunk, false, OrderSyncTrigger::Schedule, $runId),
            ));
        }
        $this->runs->endJob($runId, OrderSyncRunCountsDTO::zero(), false);

        $this->logger->info('Queued QuickBooks order catch-up chunks', ['run_id' => $runId, 'candidate_count' => \count($candidates), 'chunks' => \count($chunks)]);

        return CatchUpOrderSyncResult::queued($runId, \count($candidates), \count($chunks));
    }

    private function skip(string $reason): CatchUpOrderSyncResult
    {
        $this->logger->info('Skipped QuickBooks order catch-up sync', ['reason' => $reason]);

        return CatchUpOrderSyncResult::skipped($reason);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function refuse(string $reason, array $context = []): CatchUpOrderSyncResult
    {
        $this->logger->error('Refused QuickBooks order catch-up sync', ['reason' => $reason, ...$context]);

        return CatchUpOrderSyncResult::refused($reason);
    }
}
