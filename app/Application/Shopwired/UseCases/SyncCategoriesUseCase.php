<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Orchestrate category synchronization from ShopWired API.
 *
 * Categories are a small, stable dataset (~50 items) that changes infrequently.
 * Simple sync pattern: Fetch all → save all → done.
 */
final readonly class SyncCategoriesUseCase
{
    public function __construct(
        private CategoryClientInterface $client,
        private CategoryRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize categories from ShopWired API to local database.
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws RuntimeException When API returns zero categories (unexpected)
     */
    public function execute(): SyncResult
    {
        $this->logger->info('Starting category sync from ShopWired');

        $categories = $this->client->listAllCategories();
        $fetched = \count($categories);

        $this->logger->debug('Fetched categories from API', [
            'count' => $fetched,
        ]);

        if ($fetched === 0) {
            throw new RuntimeException('ShopWired returned zero categories - this is unexpected');
        }

        $saveResult = $this->repository->saveMany($categories);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some categories to database', [
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        $this->logger->info('Category sync completed', [
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
