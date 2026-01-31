<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Domain\Catalog\Order\ValueObjects\Order as DomainOrder;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Enums\OrderSort;
use App\Infrastructure\Shopwired\Mappers\OrderLifecycleStatusMapper;
use App\Infrastructure\Shopwired\OrderQueryParams;
use App\Infrastructure\Shopwired\Requests\OrderStatusUpdateOptions;
use App\Infrastructure\Shopwired\Responses\OrderResponse;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use DateTimeImmutable;
use Generator;

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
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * @return list<DomainOrder>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listOrdersInRangeWithDetails(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $params = OrderQueryParams::forBulkFetch()
            ->withFrom($from->getTimestamp())
            ->withTo($to->getTimestamp())
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
     * @return list<DomainOrder>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listOrdersInRange(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $params = OrderQueryParams::forBulkFetch()
            ->withFrom($from->getTimestamp())
            ->withTo($to->getTimestamp())
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::STANDARD_EMBEDS)
                    ->withFields(self::STANDARD_FIELDS),
            );

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(OrderQueryParams $p): array => $this->fetchOrderPage($p),
        );
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When order not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getOrderById(int $id): DomainOrder
    {
        $params = new ShopwiredQueryParams()
            ->withEmbeds(self::DETAIL_EMBEDS)
            ->withFields(self::DETAIL_FIELDS);

        $response = $this->transport->getResource(
            resourceType: 'Order',
            id: $id,
            endpoint: self::ENDPOINT_ORDERS,
            query: $params->toArray(),
        );

        /** @var DomainOrder */
        return self::parseSingleToDomain($response->json(), OrderResponse::class);
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getOrderCount(): int
    {
        $response = $this->transport->get(self::ENDPOINT_ORDERS . '/count');

        return self::parseCountResponse($response->json());
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
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
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function searchOrders(string $keyword): array
    {
        $response = $this->transport->get(
            self::ENDPOINT_ORDERS . '/search',
            ['keywords' => $keyword],
        );

        /** @var list<DomainOrder> */
        return self::parseWrappedArrayToDomain($response->json(), OrderResponse::class);
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When order not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     */
    public function updateOrderStatus(
        int $orderId,
        OrderLifecycleStatus $status,
        bool $notifyCustomer = false,
        ?string $trackingUrl = null,
    ): void {
        $options = new OrderStatusUpdateOptions(
            sendEmail: $notifyCustomer,
            trackingUrl: $trackingUrl,
        );

        $data = [
            'status' => OrderLifecycleStatusMapper::toShopwiredId($status),
            ...$options->toArray(),
        ];

        $this->transport->post(
            self::ENDPOINT_ORDERS . '/' . $orderId . '/status',
            $data,
        );
    }

    /**
     * Fetch a single page of orders.
     *
     * @return list<DomainOrder>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function fetchOrderPage(OrderQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT_ORDERS,
            $params->toArray(),
        );

        /** @var list<DomainOrder> */
        return self::parseArrayToDomain($response->json(), OrderResponse::class);
    }

    /**
     * Iterate orders in batches (memory-efficient).
     *
     * Orders sorted by date descending (newest first) for resilience:
     * if sync fails mid-way, recent orders are already captured.
     *
     * Uses Detail mode to include products and customFields.
     *
     * @param int|null $maxPages Maximum pages to fetch (null = all)
     *
     * @return Generator<int, list<DomainOrder>, mixed, void>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateOrderBatches(?int $maxPages = null): Generator
    {
        // IMPORTANT: withSort() must come AFTER withBaseParams() because
        // withBaseParams() replaces the entire base params object
        $params = OrderQueryParams::forBulkFetch()
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DETAIL_EMBEDS)
                    ->withFields(self::DETAIL_FIELDS),
            )
            ->withSort(OrderSort::DateDesc);

        $pageCount = 0;
        foreach (ShopwiredPaginator::pages($params, $this->fetchOrderPage(...)) as $pageNumber => $orders) {
            $pageCount++;
            yield $pageNumber => $orders;

            if ($maxPages !== null && $pageCount >= $maxPages) {
                break;
            }
        }
    }
}
