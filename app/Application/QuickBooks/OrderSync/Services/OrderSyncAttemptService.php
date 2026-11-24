<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderPreparationDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ReadyOrderDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\OrderSyncDeadlineResolver;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Results\OrderClaimResult;
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
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * Prepares, posts and records a chunk's claimed orders.
 */
final readonly class OrderSyncAttemptService
{
    public function __construct(
        private OrderSyncSettingsInterface $settings,
        private OrderSyncPreparationService $preparation,
        private QuickBooksOrderPostingInterface $posting,
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
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function attempt(#[SensitiveParameter] ApiKeyToken $accessToken, OrderSyncChunkDTO $chunk, OrderClaimResult $claim, Deadline $deadline): array
    {
        try {
            $capped = OrderSyncDeadlineResolver::resolve($claim->claimed, $deadline);
            $preparation = new OrderPreparationDTO($chunk->realmId, $claim->claimed, $this->settings->runLabels());
            $prepared = $this->preparation->prepare($accessToken, $preparation, $capped);
        } catch (Throwable $e) {
            $this->recovery->recover($claim->claimed, $e);

            throw $e;
        }

        return $this->records->record($chunk, [...$prepared->settled, ...$this->post($accessToken, $prepared->ready, $capped)]);
    }

    /**
     * @param list<ReadyOrderDTO> $ready
     *
     * @return list<OrderSyncOutcomeDTO>
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     */
    private function post(#[SensitiveParameter] ApiKeyToken $accessToken, array $ready, Deadline $deadline): array
    {
        if ($ready === []) {
            return [];
        }

        $documents = \array_map(static fn(ReadyOrderDTO $order): PostableDocumentDTO => $order->document, $ready);

        return \array_map(self::outcome(...), $ready, $this->posting->createDocuments($accessToken, $documents, $deadline));
    }

    private static function outcome(ReadyOrderDTO $order, BatchItemOutcomeResult $item): OrderSyncOutcomeDTO
    {
        $failure = SyncOutcomeResolver::forBatch($item);

        return $failure === null
            ? OrderSyncOutcomeDTO::created($order, $item->qboId ?? throw new LogicException('A created document has an id'))
            : OrderSyncOutcomeDTO::failed($order->order, $failure, $order->document->draft->docType());
    }
}
