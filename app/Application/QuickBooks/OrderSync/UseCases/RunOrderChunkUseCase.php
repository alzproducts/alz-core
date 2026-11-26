<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncDispatcherInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
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
 * Runs one attempt at a chunk and owns its re-attempts (ADR-0024).
 */
final readonly class RunOrderChunkUseCase
{
    public function __construct(
        private QuickBooksRealmInterface $realm,
        private QuickBooksTokenService $tokens,
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
     * @throws InvalidConfigurationException when the run's company is no longer the configured one
     * @throws KeyEncryptionFailedException
     * @throws OrderChunkUnfinishedException when no attempt is left
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function execute(OrderChunkRunDTO $run, Deadline $deadline): void
    {
        $this->logger->info('Running QuickBooks order chunk', ['run_id' => $run->chunk->runId, 'attempt' => $run->attempt]);

        $this->assertSameCompany($run->chunk->realmId);

        try {
            $accessToken = $this->accessToken();
        } catch (ExternalServiceUnavailableException|LockAcquisitionException $e) {
            $this->reattempt($run, $run->chunk->references, $e);

            return;
        }

        $unfinished = self::unfinished($this->syncChunk->execute($accessToken, $run, $deadline));
        if ($unfinished !== []) {
            $this->reattempt($run, $unfinished);
        }

        $this->logger->info('Ran QuickBooks order chunk', ['run_id' => $run->chunk->runId, 'unfinished' => $unfinished]);
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
     * @param non-empty-list<int> $references
     *
     * @throws OrderChunkUnfinishedException
     */
    private function reattempt(OrderChunkRunDTO $run, array $references, ?Throwable $cause = null): void
    {
        if ($run->isLastAttempt()) {
            throw new OrderChunkUnfinishedException($references, $cause);
        }

        $this->dispatcher->dispatchChunk($run->reattempt($references));
        $this->logger->info('Re-attempting QuickBooks order chunk', [
            'run_id' => $run->chunk->runId,
            'references' => $references,
            'cause' => $cause === null ? SyncReasonCode::DeadlineExceeded->value : $cause::class,
        ]);
    }

    /**
     * intentional: an `in_progress` reference is left to the sync that holds its lease.
     *
     * @return list<int>
     */
    private static function unfinished(OrderSyncResultsResult $results): array
    {
        $unfinished = [];
        foreach ($results->results as $result) {
            if ($result->reason === SyncReasonCode::DeadlineExceeded) {
                $unfinished[] = $result->reference;
            }
        }

        return $unfinished;
    }
}
