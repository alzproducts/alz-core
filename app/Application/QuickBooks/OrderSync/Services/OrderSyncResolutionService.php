<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\Mappings\DTOs\ResolvedMappingRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\BuiltOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\GoodsLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ReadyOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Resolvers\UnsentOrderResolver;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Application\QuickBooks\OrderSync\Results\OrderItemsResult;
use App\Application\QuickBooks\OrderSync\Results\OrderReadyResult;
use App\Application\QuickBooks\OrderSync\Transformers\ResolvedRefsTransformer;
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
 * Resolves built orders to the QuickBooks ids their documents need: items, then mapping refs, then customers. Items
 * come first so that an order with a missing item creates no customer.
 */
final readonly class OrderSyncResolutionService
{
    public function __construct(
        private QuickBooksOrderPostingInterface $posting,
        private QuickBooksMappingRepositoryInterface $mappings,
    ) {}

    /**
     * @param list<BuiltOrderDTO> $built
     *
     * @throws AuthenticationExpiredException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function withItems(#[SensitiveParameter] ApiKeyToken $accessToken, array $built, Deadline $deadline): OrderItemsResult
    {
        if ($built === []) {
            return OrderItemsResult::of([], null);
        }

        try {
            $items = $this->posting->findItems($accessToken, self::skus($built), $deadline);
        } catch (ExternalServiceUnavailableException|QuickBooksDeadlineExceededException $e) {
            return OrderItemsResult::of(UnsentOrderResolver::outcomes($built, $e), null);
        }

        return OrderItemsResult::of(
            \array_map(static fn(BuiltOrderDTO $order): BuiltOrderDTO|OrderSyncOutcomeDTO => self::unlessItemMissing($order, $items), $built),
            $items,
        );
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException from the mapping repository only
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     */
    public function ready(#[SensitiveParameter] ApiKeyToken $accessToken, string $realmId, OrderItemsResult $items, Deadline $deadline): OrderReadyResult
    {
        $found = $items->items;
        if ($found === null) {
            return OrderReadyResult::of([]);
        }

        // intentional: before the customer call, so that a database failure creates no customer.
        $mappings = $this->mappings->resolvedRefs($realmId);

        try {
            $customers = $this->posting->findOrCreateCustomers($accessToken, self::customerDrafts($items->open), $deadline);
        } catch (ExternalServiceUnavailableException|QuickBooksDeadlineExceededException $e) {
            return OrderReadyResult::of(UnsentOrderResolver::outcomes($items->open, $e));
        }

        return OrderReadyResult::of(\array_map(
            static fn(BuiltOrderDTO $order): OrderSyncOutcomeDTO|ReadyOrderDTO => self::readyOne($order, $customers, $found, $mappings),
            $items->open,
        ));
    }

    /**
     * @param list<BuiltOrderDTO> $built
     *
     * @return list<CustomerDraftDTO>
     */
    private static function customerDrafts(array $built): array
    {
        return \array_map(static fn(BuiltOrderDTO $order): CustomerDraftDTO => $order->draft->core->customer, $built);
    }

    private static function unlessItemMissing(BuiltOrderDTO $order, ItemLookupResult $items): BuiltOrderDTO|OrderSyncOutcomeDTO
    {
        $lacksItem = \array_any($order->draft->core->goodsLines, static fn(GoodsLineDraftDTO $line): bool => $items->itemIdFor($line->itemSku) === null);

        return $lacksItem
            ? OrderSyncOutcomeDTO::failed($order->order, SyncOutcomeResolver::forMissingItem(), $order->draft->docType())
            : $order;
    }

    private static function readyOne(
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
