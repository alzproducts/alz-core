<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\Mappings\DTOs\ResolvedMappingRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\BuiltOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentRunLabelsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\GoodsLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderPreparationDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ReadyOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Application\QuickBooks\OrderSync\Results\DocumentLookupResult;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Application\QuickBooks\OrderSync\Results\OrderPreparationResult;
use App\Application\QuickBooks\OrderSync\Transformers\DocumentDraftTransformer;
use App\Application\QuickBooks\OrderSync\Transformers\ResolvedRefsTransformer;
use App\Application\QuickBooks\OrderSync\Transformers\SourceOrderTransformer;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use LogicException;
use SensitiveParameter;

/**
 * Turns claimed orders into documents ready to post, or into their outcomes. Each step works on the orders the
 * last one left, so the DocNumber lookup of the whole chunk comes before any customer create.
 */
final readonly class OrderSyncPreparationService
{
    public function __construct(
        private QuickBooksOrderPostingInterface $posting,
        private QuickBooksMappingRepositoryInterface $mappings,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function prepare(#[SensitiveParameter] ApiKeyToken $accessToken, OrderPreparationDTO $preparation, Deadline $deadline): OrderPreparationResult
    {
        $orders = \array_map(
            static fn(ClaimedOrderDTO $order): BuiltOrderDTO|OrderSyncOutcomeDTO => self::build($order, $preparation->labels),
            $preparation->claimed,
        );
        $orders = $this->withoutExistingDocuments($accessToken, $orders, $deadline);
        $built = self::built($orders);

        return OrderPreparationResult::of([
            ...self::settled($orders),
            ...($built === [] ? [] : $this->resolved($accessToken, $preparation->realmId, $built, $deadline)),
        ]);
    }

    private static function build(ClaimedOrderDTO $order, DocumentRunLabelsDTO $labels): BuiltOrderDTO|OrderSyncOutcomeDTO
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

    /**
     * @param list<BuiltOrderDTO|OrderSyncOutcomeDTO> $orders
     *
     * @return list<BuiltOrderDTO|OrderSyncOutcomeDTO>
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function withoutExistingDocuments(#[SensitiveParameter] ApiKeyToken $accessToken, array $orders, Deadline $deadline): array
    {
        $docNumbers = \array_map(static fn(BuiltOrderDTO $order): string => $order->draft->core->docNumber, self::built($orders));
        if ($docNumbers === []) {
            return $orders;
        }

        $documents = $this->posting->findDocuments($accessToken, $docNumbers, $deadline);

        return \array_map(
            static fn(BuiltOrderDTO|OrderSyncOutcomeDTO $order): BuiltOrderDTO|OrderSyncOutcomeDTO
                => $order instanceof BuiltOrderDTO ? self::unlessExisting($order, $documents) : $order,
            $orders,
        );
    }

    private static function unlessExisting(BuiltOrderDTO $order, DocumentLookupResult $documents): BuiltOrderDTO|OrderSyncOutcomeDTO
    {
        $matches = $documents->forDocNumber($order->draft->core->docNumber);
        $ownType = $order->draft->docType();
        $match = \array_find($matches, static fn(ExistingDocumentDTO $document): bool => $document->type === $ownType) ?? $matches[0] ?? null;

        return $match === null ? $order : OrderSyncOutcomeDTO::found($order->order, $match);
    }

    private static function withItems(BuiltOrderDTO $order, ItemLookupResult $items): BuiltOrderDTO|OrderSyncOutcomeDTO
    {
        $lacksItem = \array_any($order->draft->core->goodsLines, static fn(GoodsLineDraftDTO $line): bool => $items->itemIdFor($line->itemSku) === null);

        return $lacksItem
            ? OrderSyncOutcomeDTO::failed($order->order, SyncOutcomeResolver::forMissingItem(), $order->draft->docType())
            : $order;
    }

    /**
     * @param non-empty-list<BuiltOrderDTO> $built
     *
     * @return list<OrderSyncOutcomeDTO|ReadyOrderDTO>
     *
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    private function resolved(#[SensitiveParameter] ApiKeyToken $accessToken, string $realmId, array $built, Deadline $deadline): array
    {
        $items = $this->posting->findItems($accessToken, self::skus($built), $deadline);
        $orders = \array_map(static fn(BuiltOrderDTO $order): BuiltOrderDTO|OrderSyncOutcomeDTO => self::withItems($order, $items), $built);
        $built = self::built($orders);
        if ($built === []) {
            return self::settled($orders);
        }

        $drafts = \array_map(static fn(BuiltOrderDTO $order): CustomerDraftDTO => $order->draft->core->customer, $built);
        $customers = $this->posting->findOrCreateCustomers($accessToken, $drafts, $deadline);
        $mappings = $this->mappings->resolvedRefs($realmId);
        $ready = \array_map(static fn(BuiltOrderDTO $order): OrderSyncOutcomeDTO|ReadyOrderDTO => self::ready($order, $customers, $items, $mappings), $built);

        return [...self::settled($orders), ...$ready];
    }

    private static function ready(
        BuiltOrderDTO $order,
        CustomerResolutionResult $customers,
        ItemLookupResult $items,
        ResolvedMappingRefsDTO $mappings,
    ): OrderSyncOutcomeDTO|ReadyOrderDTO {
        $customer = $customers->forDisplayName($order->draft->core->customer->displayName)
            ?? throw new LogicException('Every draft customer has a resolution');
        $failure = SyncOutcomeResolver::forCustomer($customer);
        if ($failure !== null) {
            return OrderSyncOutcomeDTO::failed($order->order, $failure, $order->draft->docType());
        }

        $match = $customer->match ?? throw new LogicException('A customer without a failure has a match');

        return self::postable($order, ResolvedRefsTransformer::build($order->draft, $match, $items, $mappings));
    }

    private static function postable(BuiltOrderDTO $order, ResolvedDocumentRefsDTO $refs): OrderSyncOutcomeDTO|ReadyOrderDTO
    {
        $failure = SyncOutcomeResolver::forMissingRefs($refs->missingFor($order->draft));

        return $failure === null
            ? new ReadyOrderDTO($order->order, new PostableDocumentDTO($order->draft, $refs))
            : OrderSyncOutcomeDTO::failed($order->order, $failure, $order->draft->docType());
    }

    /**
     * @param list<BuiltOrderDTO|OrderSyncOutcomeDTO> $orders
     *
     * @return list<BuiltOrderDTO>
     */
    private static function built(array $orders): array
    {
        return \array_values(\array_filter($orders, static fn(BuiltOrderDTO|OrderSyncOutcomeDTO $order): bool => $order instanceof BuiltOrderDTO));
    }

    /**
     * @param list<BuiltOrderDTO|OrderSyncOutcomeDTO> $orders
     *
     * @return list<OrderSyncOutcomeDTO>
     */
    private static function settled(array $orders): array
    {
        return \array_values(\array_filter($orders, static fn(BuiltOrderDTO|OrderSyncOutcomeDTO $order): bool => $order instanceof OrderSyncOutcomeDTO));
    }

    /**
     * @param list<BuiltOrderDTO> $built
     *
     * @return list<Sku> distinct
     */
    private static function skus(array $built): array
    {
        $skus = [];
        foreach ($built as $order) {
            foreach ($order->draft->core->goodsLines as $line) {
                $skus[$line->itemSku->value] = $line->itemSku;
            }
        }

        return \array_values($skus);
    }
}
