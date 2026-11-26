<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRunRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncRunCountsDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Exceptions\OrderChunkUnfinishedException;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
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
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one attempt at a chunk and owns its re-attempts (ADR-0024). A reference adds to the run's counts at the
 * attempt that ends it, so a re-attempted one counts once.
 */
final readonly class RunOrderChunkUseCase
{
    private const array REQUEUED = [SyncReasonCode::DeadlineExceeded, SyncReasonCode::QboUnavailable];

    public function __construct(
        private QuickBooksRealmInterface $realm,
        private QuickBooksTokenService $tokens,
        private SyncOrderChunkUseCase $syncChunk,
        private OrderSyncDispatcherInterface $dispatcher,
        private OrderSyncRunRepositoryInterface $runs,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws InvalidConfigurationException when the run's company is no longer the configured one
     * @throws KeyEncryptionFailedException
     * @throws OrderChunkUnfinishedException when no attempt is left
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function execute(OrderChunkRunDTO $run, Deadline $deadline): void
    {
        $this->logger->info('Running QuickBooks order chunk', ['run_id' => $run->chunk->runId, 'attempt' => $run->attempt]);

        $this->runs->markStarted($run->chunk->runId);
        $this->assertSameCompany($run->chunk->realmId);

        try {
            $accessToken = $this->accessToken();
        } catch (ExternalServiceUnavailableException|LockAcquisitionException $e) {
            $this->tokenFailed($run, $e);

            return;
        }

        $unfinished = $this->syncAndEnd($accessToken, $run, $deadline);

        $this->logger->info('Ran QuickBooks order chunk', ['run_id' => $run->chunk->runId, 'unfinished' => $unfinished]);
    }

    /**
     * A token failure leaves the whole chunk unsent.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws OrderChunkUnfinishedException
     */
    private function tokenFailed(OrderChunkRunDTO $run, ExternalServiceUnavailableException|LockAcquisitionException $e): void
    {
        $this->reattempt($run, \array_fill_keys($run->chunk->references, $e::class), OrderSyncRunCountsDTO::zero(), $e);
        $this->runs->endJob($run->chunk->runId, OrderSyncRunCountsDTO::zero(), false);
    }

    /**
     * @return list<int> the references left unfinished
     *
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws KeyEncryptionFailedException
     * @throws OrderChunkUnfinishedException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function syncAndEnd(ApiKeyToken $accessToken, OrderChunkRunDTO $run, Deadline $deadline): array
    {
        $results = $this->syncChunk->execute($accessToken, $run, $deadline);
        $unfinished = self::unfinished($results);
        $finished = self::finishedCounts($results, $unfinished);
        if ($unfinished !== []) {
            $this->reattempt($run, $unfinished, $finished);
        }

        $this->runs->endJob($run->chunk->runId, $finished, false);

        return \array_keys($unfinished);
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function assertSameCompany(string $realmId): void
    {
        if ($this->realm->realmId() !== $realmId) {
            throw new InvalidConfigurationException('QUICKBOOKS_REALM_ID', 'The run was requested for another company');
        }
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     */
    private function accessToken(): ApiKeyToken
    {
        $this->tokens->assertConnected();

        return $this->tokens->accessToken();
    }

    /**
     * The last attempt records an unsent order failed itself, so only deadline and token failures are left to throw.
     * Before it throws, it counts what it finished and what it left unsent; its failure handler then ends the job.
     *
     * @param non-empty-array<int, string> $unfinished each unsent reference's reason code, or the token failure's class
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws OrderChunkUnfinishedException
     */
    private function reattempt(OrderChunkRunDTO $run, array $unfinished, OrderSyncRunCountsDTO $finished, ?Throwable $cause = null): void
    {
        $references = \array_keys($unfinished);
        $causes = \array_values(\array_unique($unfinished));
        if ($run->isLastAttempt()) {
            $this->runs->addCounts($run->chunk->runId, $finished->withNotAttempted(\count($references)), true);

            throw new OrderChunkUnfinishedException($references, $cause);
        }

        $this->dispatcher->dispatchChunk($run->reattempt($references));
        $this->logger->info('Re-attempting QuickBooks order chunk', [
            'run_id' => $run->chunk->runId,
            'references' => $references,
            'cause' => $causes,
        ]);
    }

    /**
     * intentional: an `in_progress` reference is left to the sync that holds its lease.
     *
     * @return array<int, string> each released reference's reason code, by reference
     */
    private static function unfinished(OrderSyncResultsResult $results): array
    {
        $unfinished = [];
        foreach ($results->results as $result) {
            if ($result->outcome === SyncOutcome::NotAttempted && \in_array($result->reason, self::REQUEUED, true)) {
                $unfinished[$result->reference] = $result->reason->value;
            }
        }

        return $unfinished;
    }

    /**
     * @param array<int, string> $unfinished
     */
    private static function finishedCounts(OrderSyncResultsResult $results, array $unfinished): OrderSyncRunCountsDTO
    {
        return OrderSyncRunCountsDTO::ofResults(\array_values(\array_filter(
            $results->results,
            static fn(OrderSyncResultDTO $result): bool => !\array_key_exists($result->reference, $unfinished),
        )));
    }
}
