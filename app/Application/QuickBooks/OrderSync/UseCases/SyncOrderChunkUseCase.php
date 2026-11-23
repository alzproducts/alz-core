<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\UseCases;

use App\Application\Contracts\QuickBooks\OrderSyncSettingsInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderPreparationDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ReadyOrderDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Results\OrderSyncResultsResult;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncClaimService;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncPreparationService;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncRecordService;
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
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Syncs one chunk: claim, prepare, post, record. No database transaction spans a QuickBooks call (ADR-0012).
 */
final readonly class SyncOrderChunkUseCase
{
    public function __construct(
        private OrderSyncSettingsInterface $settings,
        private OrderSyncClaimService $claims,
        private OrderSyncPreparationService $preparation,
        private QuickBooksOrderPostingInterface $posting,
        private OrderSyncRecordService $records,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidConfigurationException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function execute(#[SensitiveParameter] ApiKeyToken $accessToken, OrderSyncChunkDTO $chunk, Deadline $deadline): OrderSyncResultsResult
    {
        $this->logger->info('Syncing QuickBooks order chunk', ['references' => $chunk->references]);

        // intentional: read before the claim, so a misconfigured label leaves no row claimed.
        $labels = $this->settings->runLabels();
        $claim = $this->claims->claim($chunk);
        $preparation = new OrderPreparationDTO($chunk->realmId, $claim->claimed, $labels);
        $prepared = $this->preparation->prepare($accessToken, $preparation, $deadline);

        $posted = $this->post($accessToken, $prepared->ready, $deadline);
        $recorded = $this->records->record($chunk, [...$prepared->settled, ...$posted]);
        $results = OrderSyncResultsResult::inRequestOrder($chunk->references, [...$claim->refused, ...$recorded]);

        $this->logger->info('Synced QuickBooks order chunk', ['counts' => $results->counts()]);

        return $results;
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
