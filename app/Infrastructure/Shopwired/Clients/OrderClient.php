<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Domain\Catalog\Order\ValueObjects\Order as DomainOrder;
use App\Infrastructure\Shopwired\OrderQueryParams;
use App\Infrastructure\Shopwired\Responses\Order as InfraOrder;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Orders API Client.
 *
 * Two-mode approach:
 * - Standard: All fields + embeds, NO products, NO customFields
 * - Detail: Standard + products + customFields
 *
 * HTTP concerns (auth, retry, timeout) delegated to ShopwiredHttpTransport.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
final readonly class OrderClient implements OrderClientInterface
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT_ORDERS = 'orders';

    /**
     * Fields for STANDARD requests.
     * All order data except products and customFields (Detail-only).
     *
     * @var list<string>
     */
    private const array STANDARD_FIELDS = [
        'id',
        'reference',
        'created',
        'archived',
        'anonymized',
        'preOrder',
        'paymentMethod',
        'total',
        'subTotal',
        'shippingTotal',
        'originalShippingTotal',
        'partialPaymentTotal',
        'totalWeight',
        'weightUnit',
        'packageWeight',
        'marketing',
        'comments',
        'trackingUrl',
        'invoiceUrl',
        'transactionId',
        'referrerId',
        'earnedRewardPoints',
        'lineItemVatCalculation',
        'deliveryDate',
        'customerSource',
        'status',
        'billingAddress',
        'shippingAddress',
        'tax',
        'customer',
        'shipping',
        'discounts',
        'fees',
        'refunds',
        'partialPayments',
        'adminComments',
        'fileArchives',
    ];

    /**
     * Fields for DETAIL requests.
     * Standard + products + customFields.
     *
     * @var list<string>
     */
    private const array DETAIL_FIELDS = [
        ...self::STANDARD_FIELDS,
        'products',
        'customFields',
    ];

    /**
     * Embeds for STANDARD requests.
     *
     * @var list<string>
     */
    private const array STANDARD_EMBEDS = [
        'status',
        'billing_address',
        'shipping_address',
        'tax',
        'customer',
        'shipping',
        'discounts',
        'fees',
        'refunds',
        'partial_payments',
        'admin_comments',
        'file_archives',
    ];

    /**
     * Embeds for DETAIL requests.
     * Standard + products + custom_fields.
     *
     * @var list<string>
     */
    private const array DETAIL_EMBEDS = [
        ...self::STANDARD_EMBEDS,
        'products',
        'custom_fields',
    ];

    public function __construct(
        private ShopwiredHttpTransport $transport,
    ) {}

    /**
     * @return list<DomainOrder>
     */
    public function listOrdersInRangeWithDetails(int $from, int $to): array
    {
        $params = OrderQueryParams::forBulkFetch()
            ->withFrom($from)
            ->withTo($to)
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DETAIL_EMBEDS)
                    ->withFields(self::DETAIL_FIELDS),
            );

        /** @var list<InfraOrder> $dtos */
        $dtos = ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(OrderQueryParams $p): array => $this->fetchOrderPage($p),
        );

        return \array_map(
            static fn(InfraOrder $dto): DomainOrder => $dto->toDomain(),
            $dtos,
        );
    }

    /**
     * @return list<DomainOrder>
     */
    public function listOrdersInRange(int $from, int $to): array
    {
        $params = OrderQueryParams::forBulkFetch()
            ->withFrom($from)
            ->withTo($to)
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::STANDARD_EMBEDS)
                    ->withFields(self::STANDARD_FIELDS),
            );

        /** @var list<InfraOrder> $dtos */
        $dtos = ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(OrderQueryParams $p): array => $this->fetchOrderPage($p),
        );

        return \array_map(
            static fn(InfraOrder $dto): DomainOrder => $dto->toDomain(),
            $dtos,
        );
    }

    public function getOrderById(int $id): DomainOrder
    {
        $params = new ShopwiredQueryParams()
            ->withEmbeds(self::DETAIL_EMBEDS)
            ->withFields(self::DETAIL_FIELDS);

        $response = $this->transport->get(
            self::ENDPOINT_ORDERS . '/' . $id,
            $params->toArray(),
        );

        /** @var InfraOrder $dto */
        $dto = self::parseSingleResponse($response->json(), InfraOrder::class);

        return $dto->toDomain();
    }

    public function getOrderCount(): int
    {
        $response = $this->transport->get(self::ENDPOINT_ORDERS . '/count');

        return self::parseCountResponse($response->json());
    }

    public function getOrderCountByStatus(int $statusId): int
    {
        $response = $this->transport->get(
            self::ENDPOINT_ORDERS . '/count',
            ['status' => $statusId],
        );

        return self::parseCountResponse($response->json());
    }

    /**
     * @return list<DomainOrder>
     */
    public function listOrders(): array
    {
        $params = new ShopwiredQueryParams()
            ->withEmbeds(self::STANDARD_EMBEDS)
            ->withFields(self::STANDARD_FIELDS);

        $response = $this->transport->get(
            self::ENDPOINT_ORDERS,
            $params->toArray(),
        );

        $collection = self::parseArrayResponse($response->json(), InfraOrder::class);

        /** @var list<InfraOrder> $dtos */
        $dtos = $collection->all();

        return \array_map(
            static fn(InfraOrder $dto): DomainOrder => $dto->toDomain(),
            $dtos,
        );
    }

    /**
     * @return list<DomainOrder>
     */
    public function searchOrders(string $keyword): array
    {
        $params = new ShopwiredQueryParams()
            ->withEmbeds(self::STANDARD_EMBEDS)
            ->withFields(self::STANDARD_FIELDS);

        $response = $this->transport->get(
            self::ENDPOINT_ORDERS . '/search',
            ['keywords' => $keyword, ...$params->toArray()],
        );

        // Search endpoint returns {totalItems, items} wrapper
        $data = $response->json();
        $items = \is_array($data) && isset($data['items']) ? $data['items'] : $data;

        $collection = self::parseArrayResponse($items, InfraOrder::class);

        /** @var list<InfraOrder> $dtos */
        $dtos = $collection->all();

        return \array_map(
            static fn(InfraOrder $dto): DomainOrder => $dto->toDomain(),
            $dtos,
        );
    }

    /**
     * Fetch a single page of orders (returns Infrastructure DTOs for internal use).
     *
     * @return list<InfraOrder>
     */
    private function fetchOrderPage(OrderQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT_ORDERS,
            $params->toArray(),
        );

        $collection = self::parseArrayResponse($response->json(), InfraOrder::class);

        /** @var list<InfraOrder> */
        return $collection->all();
    }
}
