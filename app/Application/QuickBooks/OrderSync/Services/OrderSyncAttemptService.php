<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderPreparationDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ReadyOrderDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncDeadlineResolver;
use App\Application\QuickBooks\OrderSync\Results\OrderClaimResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use SensitiveParameter;
use Throwable;

/**
 * Prepares, posts and records a chunk's claimed orders. A throw before the batch create releases the rows; from the
 * batch create on, only a rejected token does.
 */
final readonly class OrderSyncAttemptService
{
    public function __construct(
        private OrderSyncSettingsInterface $settings,
        private OrderSyncFloorService $floor,
        private OrderSyncPreparationService $preparation,
        private OrderSyncExistingDocumentService $existing,
        private OrderSyncResolutionService $resolution,
        private OrderSyncPostingService $posting,
        private OrderSyncRecordService $records,
        private OrderSyncRecoveryService $recovery,
    ) {}

    /**
     * @return list<OrderSyncResultDTO> one per claimed order
     *
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidConfigurationException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function attempt(#[SensitiveParameter] ApiKeyToken $accessToken, OrderSyncChunkDTO $chunk, OrderClaimResult $claim, Deadline $deadline): array
    {
        try {
            $capped = OrderSyncDeadlineResolver::resolve($claim->claimed, $deadline);
            [$ready, $results] = $this->prepare($accessToken, $chunk, $claim, $capped);
        } catch (Throwable $e) {
            $this->recovery->recover($claim->claimed, $e);

            throw $e;
        }

        try {
            $posted = $this->posting->post($accessToken, $ready, $capped);
        } catch (AuthenticationExpiredException $e) {
            $this->recovery->recover($claim->claimed, $e);

            throw $e;
        }

        return [...$results, ...$this->records->record($chunk, $posted)];
    }

    /**
     * Each step's settled orders are recorded before the next QuickBooks call, so a later throw cannot release them.
     * intentional: the floor split runs before the DocNumber lookup, so a pre-floor order QuickBooks already holds is
     * refused, not recorded as synced.
     *
     * @return array{list<ReadyOrderDTO>, list<OrderSyncResultDTO>}
     *
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidConfigurationException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function prepare(#[SensitiveParameter] ApiKeyToken $accessToken, OrderSyncChunkDTO $chunk, OrderClaimResult $claim, Deadline $deadline): array
    {
        $floor = $this->floor->withinFloor($claim->claimed);
        $results = $this->records->record($chunk, $floor->settled);
        $built = $this->preparation->build(new OrderPreparationDTO($chunk->realmId, $floor->open, $this->settings->runLabels()));
        $results = [...$results, ...$this->records->record($chunk, $built->settled)];
        $open = $this->existing->withoutExisting($accessToken, $built->open, $deadline);
        $results = [...$results, ...$this->records->record($chunk, $open->settled)];
        $items = $this->resolution->withItems($accessToken, $open->open, $deadline);
        $results = [...$results, ...$this->records->record($chunk, $items->settled)];
        $ready = $this->resolution->ready($accessToken, $chunk->realmId, $items, $deadline);

        return [$ready->ready, [...$results, ...$this->records->record($chunk, $ready->settled)]];
    }
}
