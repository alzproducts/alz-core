<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ReadyOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncFailureDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\Shared\ValueObjects\Deadline;
use LogicException;
use SensitiveParameter;

/**
 * Creates a chunk's documents in one batch call.
 */
final readonly class OrderSyncPostingService
{
    public function __construct(
        private QuickBooksOrderPostingInterface $posting,
    ) {}

    /**
     * @param list<ReadyOrderDTO> $ready
     *
     * @return list<OrderSyncOutcomeDTO>
     *
     * @throws AuthenticationExpiredException
     */
    public function post(#[SensitiveParameter] ApiKeyToken $accessToken, array $ready, Deadline $deadline): array
    {
        if ($ready === []) {
            return [];
        }

        $documents = \array_map(static fn(ReadyOrderDTO $order): PostableDocumentDTO => $order->document, $ready);

        try {
            return \array_map(self::outcome(...), $ready, $this->posting->createDocuments($accessToken, $documents, $deadline));
        } catch (QuickBooksDeadlineExceededException) {
            return \array_map(static fn(ReadyOrderDTO $order): OrderSyncOutcomeDTO => OrderSyncOutcomeDTO::released($order->order), $ready);
        } catch (ExternalServiceUnavailableException) {
            return self::failed($ready, SyncOutcomeResolver::forUnavailable(null));
        }
    }

    /**
     * @param list<ReadyOrderDTO> $ready
     *
     * @return list<OrderSyncOutcomeDTO>
     */
    private static function failed(array $ready, SyncFailureDTO $failure): array
    {
        return \array_map(
            static fn(ReadyOrderDTO $order): OrderSyncOutcomeDTO
                => OrderSyncOutcomeDTO::failed($order->order, $failure, $order->document->draft->docType()),
            $ready,
        );
    }

    private static function outcome(ReadyOrderDTO $order, BatchItemOutcomeResult $item): OrderSyncOutcomeDTO
    {
        $failure = SyncOutcomeResolver::forBatch($item);

        return $failure === null
            ? OrderSyncOutcomeDTO::created($order, $item->qboId ?? throw new LogicException('A created document has an id'))
            : OrderSyncOutcomeDTO::failed($order->order, $failure, $order->document->draft->docType());
    }
}
