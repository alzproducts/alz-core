<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Infrastructure\Shopwired\OrderQueryParams;
use App\Infrastructure\Shopwired\Responses\Order;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Orders API Client.
 *
 * Handles order retrieval operations from the ShopWired API.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
 *
 * Note: Returns Infrastructure DTOs directly for smoke testing.
 * Interface and domain conversion will be added after validating parsing.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
final readonly class OrderClient
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT_ORDERS = 'orders';

    /**
     * Fields for SUMMARY requests (list endpoints).
     * Excludes products, adminComments, fileArchives to reduce payload.
     *
     * @var list<string>
     */
    private const array SUMMARY_FIELDS = [
        'id',
        'reference',
        'created',
        'archived',
        'anonymized',
        'preOrder',
        'trackingUrl',
        'invoiceUrl',
        'paymentMethod',
        'transactionId',
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
        'deliveryDate',
        'earnedRewardPoints',
        'lineItemVatCalculation',
        'referrerId',
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
        'customFields',
    ];

    /**
     * Fields for DETAIL requests (getById, listWithDetails).
     * Includes products, adminComments, fileArchives.
     *
     * @var list<string>
     */
    private const array DETAIL_FIELDS = [
        ...self::SUMMARY_FIELDS,
        'products',
        'adminComments',
        'fileArchives',
    ];

    /**
     * Embeds for order requests.
     *
     * @var list<string>
     */
    private const array DEFAULT_EMBEDS = [
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
        'custom_fields',
    ];

    /**
     * Additional embeds for detail requests.
     *
     * @var list<string>
     */
    private const array DETAIL_EMBEDS = [
        ...self::DEFAULT_EMBEDS,
        'products',
        'admin_comments',
        'file_archives',
    ];

    public function __construct(
        private ShopwiredHttpTransport $transport,
    ) {}

    /**
     * List orders within a date range with FULL details including products.
     *
     * Use for syncs requiring complete order data (e.g., Mixpanel daily sync).
     * Heavier payload but avoids N+1 getOrderById calls.
     *
     * @return list<Order> Orders with ALL fields populated
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

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(OrderQueryParams $p): array => $this->fetchOrderPage($p),
        );
    }

    /**
     * List orders within a date range - SUMMARY fields only.
     *
     * @return list<Order> Orders with nullable detail fields (products=null, etc.)
     */
    public function listOrdersInRange(int $from, int $to): array
    {
        $params = OrderQueryParams::forBulkFetch()
            ->withFrom($from)
            ->withTo($to)
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DEFAULT_EMBEDS)
                    ->withFields(self::SUMMARY_FIELDS),
            );

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(OrderQueryParams $p): array => $this->fetchOrderPage($p),
        );
    }

    /**
     * Get a single order by ID with ALL fields populated.
     */
    public function getOrderById(int $id): Order
    {
        $params = new ShopwiredQueryParams()
            ->withEmbeds(self::DETAIL_EMBEDS)
            ->withFields(self::DETAIL_FIELDS);

        $response = $this->transport->get(
            self::ENDPOINT_ORDERS . '/' . $id,
            $params->toArray(),
        );

        /** @var Order */
        return self::parseSingleResponse($response->json(), Order::class);
    }

    /**
     * Get total order count.
     */
    public function getOrderCount(): int
    {
        $response = $this->transport->get(self::ENDPOINT_ORDERS . '/count');

        return self::parseCountResponse($response->json());
    }

    /**
     * Fetch a single page of orders.
     *
     * @return list<Order>
     */
    private function fetchOrderPage(OrderQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT_ORDERS,
            $params->toArray(),
        );

        $collection = self::parseArrayResponse($response->json(), Order::class);

        /** @var list<Order> */
        return $collection->toArray();
    }
}
