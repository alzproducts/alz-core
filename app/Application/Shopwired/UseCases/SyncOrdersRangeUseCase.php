<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate order synchronization from ShopWired API to local database (date-range based).
 *
 * Fetches orders from ShopWired API within a date range and persists them
 * locally. Uses continue-on-failure semantics: individual save failures
 * are logged and counted, but processing continues.
 *
 * Typical usage: hourly scheduled job with 2-hour overlap window.
 *
 * @see SyncOrdersUseCase For generator-based full/quick sync (recommended for bulk operations)
 */
final readonly class SyncOrdersRangeUseCase
{
    public function __construct(
        private OrderClientInterface $orderClient,
        private OrderRepositoryInterface $orderRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize orders from ShopWired API to local database.
     *
     * @param DateTimeImmutable $from Start of date range (inclusive)
     * @param DateTimeImmutable $to End of date range (inclusive)
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotAvailableException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(DateTimeImmutable $from, DateTimeImmutable $to): SyncResult
    {
        // Fetch orders from ShopWired API (with full details for persistence)
        $orders = $this->orderClient->listOrdersInRangeWithDetails($from, $to);

        if ($orders === []) {
            $this->logger->info('No orders found in date range', [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ]);

            return SyncResult::empty();
        }

        // Persist to local database (continue on individual failures)
        $saveResult = $this->orderRepository->saveMany($orders);

        if ($saveResult->hasFailures()) {
            $this->logger->warning('Some orders failed to save', [
                'failed_references' => $saveResult->failedReferences,
            ]);
        }

        $result = new SyncResult(
            fetched: \count($orders),
            saved: $saveResult->succeeded,
            failed: $saveResult->failed,
            failedReferences: $saveResult->failedReferences,
        );

        $this->logger->info('Order range sync completed', [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'fetched' => $result->fetched,
            'saved' => $result->saved,
            'failed' => $result->failed,
        ]);

        return $result;
    }
}
