<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\FilterGroupClientInterface;
use App\Application\Contracts\Shopwired\FilterGroupRepositoryInterface;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Orchestrate filter group definition synchronization from ShopWired API.
 *
 * Filter group definitions describe the faceted navigation categories
 * (e.g., "Size", "Colour", "VAT Relief Eligible"). This is a small, stable
 * dataset (~10-20 groups) that changes infrequently.
 *
 * Simple sync pattern: Fetch all → save all → done.
 */
final readonly class SyncFilterGroupsUseCase
{
    public function __construct(
        private FilterGroupClientInterface $client,
        private FilterGroupRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize filter group definitions from ShopWired API to local database.
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws RuntimeException When API returns zero filter groups (unexpected)
     */
    public function execute(): SyncResult
    {
        $this->logger->info('Starting filter group definitions sync from ShopWired');

        $definitions = $this->client->listAll();
        $fetched = \count($definitions);

        $this->logger->debug('Fetched filter group definitions from API', [
            'count' => $fetched,
        ]);

        if ($fetched === 0) {
            throw new RuntimeException('ShopWired returned zero filter groups - this is unexpected');
        }

        $saveResult = $this->repository->saveMany($definitions);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some filter group definitions to database', [
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        $this->logger->info('Filter group sync completed', [
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
