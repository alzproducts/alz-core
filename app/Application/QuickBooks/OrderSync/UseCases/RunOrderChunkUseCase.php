<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Enums\SyncReasonCode;
use App\Application\QuickBooks\OrderSync\Exceptions\OrderChunkUnfinishedException;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncFloorService;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncPreflightService;
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
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one attempt at a chunk and owns its re-attempts (ADR-0024).
 */
final readonly class RunOrderChunkUseCase
{
    private const array REQUEUED = [SyncReasonCode::DeadlineExceeded, SyncReasonCode::QboUnavailable];

    public function __construct(
        private OrderSyncPreflightService $preflight,
        private QuickBooksTokenService $tokens,
        private OrderSyncFloorService $floor,
        private SyncOrderChunkUseCase $syncChunk,
        private OrderSyncDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws InvalidConfigurationException when the run's company is no longer the configured one, or the backfill floor
     *                                       date is unset
     * @throws KeyEncryptionFailedException
     * @throws OrderChunkUnfinishedException when no attempt is left
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function execute(OrderChunkRunDTO $run, Deadline $deadline): void
    {
        $this->logger->info('Running QuickBooks order chunk', ['run_id' => $run->chunk->runId, 'attempt' => $run->attempt]);

        $this->assertConfigured($run->chunk->realmId);

        try {
            $accessToken = $this->tokens->accessToken();
        } catch (ExternalServiceUnavailableException|LockAcquisitionException $e) {
            $this->reattempt($run, $run->chunk->references, [$e::class], $e);

            return;
        }

        $unfinished = self::unfinished($this->syncChunk->execute($accessToken, $run, $deadline));
        if ($unfinished !== []) {
            $this->reattempt($run, \array_keys($unfinished), \array_values(\array_unique($unfinished)));
        }

        $this->logger->info('Ran QuickBooks order chunk', ['run_id' => $run->chunk->runId, 'unfinished' => \array_keys($unfinished)]);
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function assertConfigured(string $realmId): void
    {
        $this->preflight->assertSameCompany($realmId);

        // intentional: the attempt reads the floor it uses; this read makes an unset one throw before a token failure re-attempts.
        $this->floor->floorStart();
    }

    /**
     * The last attempt records an unsent order failed itself, so only deadline and token failures are left to throw.
     *
     * @param non-empty-list<int> $references
     * @param non-empty-list<string> $causes the reason codes, or the token failure's class
     *
     * @throws OrderChunkUnfinishedException
     */
    private function reattempt(OrderChunkRunDTO $run, array $references, array $causes, ?Throwable $cause = null): void
    {
        if ($run->isLastAttempt()) {
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
}
