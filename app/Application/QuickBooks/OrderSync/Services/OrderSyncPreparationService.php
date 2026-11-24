<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\BuiltOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderPreparationDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Resolvers\UnsentOrderResolver;
use App\Application\QuickBooks\OrderSync\Results\DocumentLookupResult;
use App\Application\QuickBooks\OrderSync\Results\OrderPreparationResult;
use App\Application\QuickBooks\OrderSync\Transformers\DocumentDraftTransformer;
use App\Application\QuickBooks\OrderSync\Transformers\SourceOrderTransformer;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use LogicException;
use SensitiveParameter;

/**
 * Builds claimed orders' drafts, then settles each order QuickBooks already holds a document for.
 */
final readonly class OrderSyncPreparationService
{
    public function __construct(
        private QuickBooksOrderPostingInterface $posting,
    ) {}

    public function build(OrderPreparationDTO $preparation): OrderPreparationResult
    {
        return OrderPreparationResult::of(\array_map(
            static fn(ClaimedOrderDTO $order): BuiltOrderDTO|OrderSyncOutcomeDTO => self::buildOne($order, $preparation->labels),
            $preparation->claimed,
        ));
    }

    /**
     * @param list<BuiltOrderDTO> $built
     *
     * @throws AuthenticationExpiredException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function withoutExisting(#[SensitiveParameter] ApiKeyToken $accessToken, array $built, Deadline $deadline): OrderPreparationResult
    {
        if ($built === []) {
            return OrderPreparationResult::of([]);
        }

        $docNumbers = \array_map(static fn(BuiltOrderDTO $order): string => $order->draft->core->docNumber, $built);

        try {
            $documents = $this->posting->findDocuments($accessToken, $docNumbers, $deadline);
        } catch (ExternalServiceUnavailableException|QuickBooksDeadlineExceededException $e) {
            return OrderPreparationResult::of(UnsentOrderResolver::outcomes($built, $e));
        }

        return OrderPreparationResult::of(\array_map(
            static fn(BuiltOrderDTO $order): BuiltOrderDTO|OrderSyncOutcomeDTO => self::unlessExisting($order, $documents),
            $built,
        ));
    }

    private static function buildOne(ClaimedOrderDTO $order, DocumentRunLabelsDTO $labels): BuiltOrderDTO|OrderSyncOutcomeDTO
    {
        $source = SourceOrderTransformer::fromRow($order->row);
        if ($source->defect !== null) {
            return OrderSyncOutcomeDTO::failed($order, SyncOutcomeResolver::forDefect($source->defect, $order->claim->storedCode), null);
        }

        $result = DocumentDraftTransformer::build($source->order ?? throw new LogicException('A source order has an order or a defect'), $labels);
        if ($result->failReason !== null) {
            return OrderSyncOutcomeDTO::failed($order, SyncOutcomeResolver::forBuildFailure($result->failReason, $order->claim->storedCode), null);
        }

        return new BuiltOrderDTO($order, $result->draft ?? throw new LogicException('A built order has a draft'));
    }

    private static function unlessExisting(BuiltOrderDTO $order, DocumentLookupResult $documents): BuiltOrderDTO|OrderSyncOutcomeDTO
    {
        $matches = $documents->forDocNumber($order->draft->core->docNumber);
        $ownType = $order->draft->docType();
        $match = \array_find($matches, static fn(ExistingDocumentDTO $document): bool => $document->type === $ownType) ?? $matches[0] ?? null;

        return $match === null ? $order : OrderSyncOutcomeDTO::found($order->order, $match);
    }
}
