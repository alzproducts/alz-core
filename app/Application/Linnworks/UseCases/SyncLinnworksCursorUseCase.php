<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Inventory\SyncCursorRepositoryInterface;
use App\Application\Enums\SyncCursorType;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Cursor management wrapper for Linnworks order sync.
 *
 * Reads the stored cursor, delegates to SyncLinnworksOrdersUseCase,
 * and advances the cursor to result.latestLastUpdated on success.
 *
 * Pattern: follows SyncDeltaStockToShopwiredUseCase for cursor logic.
 */
final readonly class SyncLinnworksCursorUseCase
{
    /**
     * Fallback window when no cursor exists (first run).
     */
    private const int DEFAULT_LOOKBACK_HOURS = 24;

    /**
     * Maximum lookback when cursor is stale.
     *
     * Caps the query window to keep the cursor sync lightweight.
     * Wider tiers (hourly/daily/weekly) handle older orders.
     */
    private const int MAX_LOOKBACK_HOURS = 2;

    public function __construct(
        private SyncLinnworksOrdersUseCase $ordersUseCase,
        private SyncCursorRepositoryInterface $cursorRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute cursor-based order sync.
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When Linnworks API or database unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws DatabaseOperationFailedException When cursor persistence fails
     * @throws DuplicateRecordException On cursor constraint violation
     */
    public function execute(): SyncResult
    {
        $storedCursor = $this->cursorRepository->getLastSyncDate(SyncCursorType::LinnworksOrdersCursor);
        $fromDate = $this->resolveFromDate($storedCursor);

        $this->logger->info('Linnworks cursor order sync: starting', [
            'stored_cursor' => $storedCursor?->format('Y-m-d H:i:s'),
            'from_date' => $fromDate->format('Y-m-d H:i:s'),
        ]);

        $result = $this->ordersUseCase->execute($fromDate);
        $this->advanceCursorIfReceived($result);

        return $result;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function advanceCursorIfReceived(SyncResult $result): void
    {
        if ($result->latestLastUpdated === null) {
            return;
        }

        $this->cursorRepository->updateLastSyncDate(
            SyncCursorType::LinnworksOrdersCursor,
            $result->latestLastUpdated,
        );

        $this->logger->info('Linnworks cursor order sync: cursor advanced', [
            'new_cursor' => $result->latestLastUpdated->format('Y-m-d H:i:s'),
            'fetched' => $result->fetched,
            'saved' => $result->saved,
        ]);
    }

    /**
     * Resolve the effective fromDate from the stored cursor.
     *
     * - No cursor (first run): look back DEFAULT_LOOKBACK_HOURS
     * - Cursor older than MAX_LOOKBACK_HOURS: cap to MAX_LOOKBACK_HOURS
     * - Cursor within MAX_LOOKBACK_HOURS: use as-is
     */
    private function resolveFromDate(?DateTimeImmutable $cursor): DateTimeImmutable
    {
        if ($cursor === null) {
            return new DateTimeImmutable(\sprintf('-%d hours', self::DEFAULT_LOOKBACK_HOURS));
        }

        $maxLookback = new DateTimeImmutable(\sprintf('-%d hours', self::MAX_LOOKBACK_HOURS));

        if ($cursor < $maxLookback) {
            $this->logger->info('Linnworks cursor order sync: cursor is stale, capping lookback', [
                'cursor' => $cursor->format('Y-m-d H:i:s'),
                'capped_to' => $maxLookback->format('Y-m-d H:i:s'),
            ]);

            return $maxLookback;
        }

        return $cursor;
    }
}
