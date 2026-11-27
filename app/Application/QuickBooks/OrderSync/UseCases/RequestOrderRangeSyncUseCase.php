<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\OrderSyncSourceQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRequestDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\DTOs\RequestedOrderSyncRunDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderRangeLimitResolver;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncCalendarResolver;
use App\Application\QuickBooks\OrderSync\Results\OrderRangeSyncAcceptedResult;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncPreflightService;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncRunService;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\QuickBooks\Exceptions\QuickBooksMappingsUnresolvedException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Accepts a range to sync. Everything a caller can act on is checked here, before anything is queued.
 */
final readonly class RequestOrderRangeSyncUseCase
{
    public function __construct(
        private OrderSyncSettingsInterface $settings,
        private OrderSyncPreflightService $preflight,
        private QuickBooksTokenService $tokens,
        private OrderSyncSourceQueryRepositoryInterface $sourceOrders,
        private OrderSyncRunService $runs,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     * @throws QuickBooksMappingsUnresolvedException
     * @throws Throwable when queueing the range job fails
     * @throws ValidationFailedException when the floor is unset or the range passes a limit
     */
    public function execute(OrderRangeRequestDTO $request, string $runId, DateTimeImmutable $now): OrderRangeSyncAcceptedResult
    {
        $this->logger->info('Requesting QuickBooks order range sync', ['run_id' => $runId]);

        OrderRangeLimitResolver::assertWithinLimits($request->range, $now, $this->settings->backfillFloor());
        $realmId = $this->preflight->realmReadyToSync();
        // intentional: the token is unused; this refuses now what every chunk would fail on.
        $this->tokens->accessToken();

        $count = $this->sourceOrders->countPlacedIn(...OrderSyncCalendarResolver::rangeBounds($request->range->from, $request->range->to));
        if ($count > 0) {
            $this->runs->open(
                new RequestedOrderSyncRunDTO($runId, OrderSyncTrigger::Range, $request->forceRetry, $request->range, null, $count, $now),
                new OrderRangeRunDTO($realmId, $request->range, $request->forceRetry, $runId),
            );
        }

        $this->logger->info('Requested QuickBooks order range sync', ['run_id' => $runId, 'candidate_count' => $count]);

        return $count > 0 ? OrderRangeSyncAcceptedResult::queued($runId, $count) : OrderRangeSyncAcceptedResult::nothingToSync();
    }
}
