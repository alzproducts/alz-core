<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Orchestrate brand synchronization from ShopWired API.
 *
 * Brands are a small, stable dataset (~30 items) that changes infrequently.
 * Simple sync pattern: Fetch all → save all → done.
 */
final readonly class SyncBrandsUseCase
{
    public function __construct(
        private BrandClientInterface $client,
        private BrandRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize brands from ShopWired API to local database.
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws RuntimeException When API returns zero brands (unexpected)
     */
    public function execute(): SyncResult
    {
        $this->logger->info('Starting brand sync from ShopWired');

        $brands = $this->client->listAllBrands();
        $fetched = \count($brands);

        $this->logger->debug('Fetched brands from API', [
            'count' => $fetched,
        ]);

        if ($fetched === 0) {
            throw new RuntimeException('ShopWired returned zero brands - this is unexpected');
        }

        $saveResult = $this->repository->saveMany($brands);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some brands to database', [
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        $this->logger->info('Brand sync completed', [
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
