<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncAttemptService;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncClaimService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Syncs one chunk: claims its rows, then attempts them. No database transaction spans a QuickBooks call (ADR-0012).
 */
final readonly class SyncOrderChunkUseCase
{
    public function __construct(
        private OrderSyncSettingsInterface $settings,
        private OrderSyncClaimService $claims,
        private OrderSyncAttemptService $attempts,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidConfigurationException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function execute(#[SensitiveParameter] ApiKeyToken $accessToken, OrderSyncChunkDTO $chunk, Deadline $deadline): OrderSyncResultsResult
    {
        $this->logger->info('Syncing QuickBooks order chunk', ['references' => $chunk->references]);

        // intentional: the attempt reads the labels it uses; this read makes a misconfigured one throw before any claim.
        $this->settings->runLabels();
        $claim = $this->claims->claim($chunk, $deadline);
        $attempted = $this->attempts->attempt($accessToken, $chunk, $claim, $deadline);
        $results = OrderSyncResultsResult::inRequestOrder($chunk->references, [...$claim->refused, ...$attempted]);

        $this->logger->info('Synced QuickBooks order chunk', ['counts' => $results->counts()]);

        return $results;
    }
}
