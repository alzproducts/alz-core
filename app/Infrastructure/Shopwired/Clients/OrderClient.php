<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Domain\Catalog\Order\ValueObjects\Order as DomainOrder;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Enums\OrderSort;
use App\Infrastructure\Shopwired\OrderQueryParams;
use App\Infrastructure\Shopwired\Responses\OrderResponse;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use DateTimeImmutable;
use Generator;

/**
 * ShopWired Orders API Client.
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
     * Fields included in order requests.
     *
     * @var list<string>
     */
    private const array FIELDS = [
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
        'products',
        'customFields',
    ];

    /**
     * Embeds included in order requests.
     *
     * @var list<string>
     */
    private const array EMBEDS = [
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
     * @throws ResourceNotAvailableException When resource not found (404)
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
                    ->withEmbeds(self::EMBEDS)
                    ->withFields(self::FIELDS),
            );

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(OrderQueryParams $p): array => $this->fetchOrderPage($p),
        );
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When order not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getOrderById(int $id): DomainOrder
    {
        $params = new ShopwiredQueryParams()
            ->withEmbeds(self::EMBEDS)
            ->withFields(self::FIELDS);

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
     * Fetch a single page of orders.
     *
     * @return list<DomainOrder>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
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
     * @param int|null $maxPages Maximum pages to fetch (null = all)
     *
     * @return Generator<int, list<DomainOrder>, mixed, void>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
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
                    ->withEmbeds(self::EMBEDS)
                    ->withFields(self::FIELDS),
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
