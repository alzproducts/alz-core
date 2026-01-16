<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\CustomFieldClientInterface;
use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Application\Shopwired\ValueObjects\SyncResult;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\ResourceNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate custom field definition synchronization from ShopWired API.
 *
 * Custom field definitions are the schema/metadata describing what custom fields
 * exist for products, categories, customers, etc. This is a small, stable dataset
 * (~100-150 definitions) that changes infrequently.
 *
 * Unlike customer sync, this use case is simple:
 * - No pagination/batching complexity (small dataset)
 * - No trade/non-trade split (single endpoint)
 * - Fetch all → save all → done
 */
final readonly class SyncCustomFieldsUseCase
{
    public function __construct(
        private CustomFieldClientInterface $client,
        private CustomFieldRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize custom field definitions from ShopWired API to local database.
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails or returns no definitions
     */
    public function execute(): SyncResult
    {
        $this->logger->info('Starting custom field definitions sync from ShopWired');

        $definitions = $this->client->listAll();
        $fetched = \count($definitions);

        $this->logger->debug('Fetched custom field definitions from API', [
            'count' => $fetched,
        ]);

        $saveResult = $this->repository->saveMany($definitions);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some custom field definitions to database', [
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        $this->logger->info('Custom field sync completed', [
            'fetched' => $fetched,
            'saved' => $saveResult->succeeded,
            'failed' => $saveResult->failed,
        ]);

        return new SyncResult(
            fetched: $fetched,
            saved: $saveResult->succeeded,
            failed: $saveResult->failed,
            failedReferences: $saveResult->failedReferences,
        );
    }
}
