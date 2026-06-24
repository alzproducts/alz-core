<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Domain\Customer\ValueObjects\Customer as DomainCustomer;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\CustomerQueryParams;
use App\Infrastructure\Shopwired\Enums\CustomerSort;
use App\Infrastructure\Shopwired\Responses\CustomerResponse;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use Generator;

/**
 * ShopWired Customers API Client.
 *
 * Handles customer retrieval operations from the ShopWired API.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
 *
 * NOTE: Methods are explicitly named (NonTrade/Trade) because the ShopWired API
 * does not support fetching all customers in a single request. See interface
 * docblock for full explanation of the trade vs non-trade distinction.
 *
 * @see https://shopwired.readme.io/reference/listcustomers
 */
final readonly class CustomerClient implements CustomerClientInterface
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT_CUSTOMERS = 'customers';

    /**
     * Default embeds for customer requests.
     *
     * @var list<string>
     */
    private const array DEFAULT_EMBEDS = ['country', 'state', 'wishlists', 'custom_fields'];

    /**
     * Default fields for customer requests.
     *
     * Must include 'customFields' (camelCase) when 'custom_fields' embed is used.
     * Without explicit fields, customFields data is not returned.
     *
     * @var list<string>
     */
    private const array DEFAULT_FIELDS = [
        'id',
        'createdAt',
        'tradeGroupId',
        'adminCreated',
        'autoCreated',
        'email',
        'firstName',
        'lastName',
        'companyName',
        'trade',
        'active',
        'credit',
        'discount',
        'costPriceMultiplier',
        'phone',
        'mobilePhone',
        'website',
        'vatNumber',
        'acceptsMarketing',
        'addressLine1',
        'addressLine2',
        'addressLine3',
        'city',
        'province',
        'postcode',
        'rewardPoints',
        'notes',
        'country',
        'state',
        'wishlists',
        'customFields',
    ];

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * Iterate NON-TRADE customers in batches (memory-efficient).
     *
     * Uses created_desc (newest first) so recent customers are prioritized if sync fails mid-way.
     * Yields batches of ~100 non-trade customers per page.
     *
     * @return Generator<int, list<DomainCustomer>, mixed, void> Yields batches (page number as key)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function iterateNonTradeCustomerBatches(): Generator
    {
        // IMPORTANT: withSort() must come AFTER withBaseParams() because
        // withBaseParams() replaces the entire base params object
        $params = CustomerQueryParams::forBulkFetch()
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DEFAULT_EMBEDS)
                    ->withFields(self::DEFAULT_FIELDS),
            )
            ->withSort(CustomerSort::CreatedDesc);

        yield from ShopwiredPaginator::pages(
            params: $params,
            fetchPage: fn(CustomerQueryParams $p): array => $this->fetchCustomerPage($p),
            knownTotal: $this->getNonTradeCustomerCount(),
        );
    }

    /**
     * Iterate customers in batches (memory-efficient).
     *
     * Yields trade customers first (newest first), then non-trade customers (newest first).
     * Both can be limited by page count, or null to fetch all.
     *
     * Page numbers are sequential across both passes (trade pages 1-N, non-trade N+1-M).
     *
     * @param int|null $maxTradePages Max trade pages (null = all ~5 pages, 1 page ≈ 100 customers)
     * @param int|null $maxNonTradePages Max non-trade pages (null = all ~677 pages, 1 page ≈ 100 customers)
     *
     * @return Generator<int, list<DomainCustomer>, mixed, void> Yields batches (page number as key)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateCustomerBatches(
        ?int $maxTradePages = null,
        ?int $maxNonTradePages = null,
    ): Generator {
        $pageNumber = 0;

        // First pass: trade customers (B2B accounts, ~5 pages total)
        $tradePageCount = 0;
        foreach ($this->iterateTradeCustomerBatches() as $batch) {
            $pageNumber++;
            $tradePageCount++;
            yield $pageNumber => $batch;

            if ($maxTradePages !== null && $tradePageCount >= $maxTradePages) {
                break;
            }
        }

        // Second pass: non-trade customers (B2C, ~677 pages total)
        $nonTradePageCount = 0;
        foreach ($this->iterateNonTradeCustomerBatches() as $batch) {
            $pageNumber++;
            $nonTradePageCount++;
            yield $pageNumber => $batch;

            if ($maxNonTradePages !== null && $nonTradePageCount >= $maxNonTradePages) {
                break;
            }
        }
    }

    /**
     * Iterate TRADE customers in batches (memory-efficient).
     *
     * Uses created_desc (newest first) so recent customers are prioritized if sync fails mid-way.
     * Yields batches of ~100 trade customers per page.
     *
     * @return Generator<int, list<DomainCustomer>, mixed, void> Yields batches (page number as key)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function iterateTradeCustomerBatches(): Generator
    {
        // IMPORTANT: withSort() must come AFTER withBaseParams() because
        // withBaseParams() replaces the entire base params object
        $params = CustomerQueryParams::forBulkFetch()
            ->withTrade(true)
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DEFAULT_EMBEDS)
                    ->withFields(self::DEFAULT_FIELDS),
            )
            ->withSort(CustomerSort::CreatedDesc);

        yield from ShopwiredPaginator::pages(
            params: $params,
            fetchPage: fn(CustomerQueryParams $p): array => $this->fetchCustomerPage($p),
            knownTotal: $this->getTradeCustomerCount(),
        );
    }

    /**
     * Get a single customer by ID.
     *
     * Works for both trade and non-trade customers — the ID is globally unique.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When customer not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getCustomerById(int $id): DomainCustomer
    {
        $params = (new ShopwiredQueryParams())
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withFields(self::DEFAULT_FIELDS);

        $response = $this->transport->get(self::ENDPOINT_CUSTOMERS . '/' . $id, $params->toArray());

        /** @var DomainCustomer */
        return self::parseSingleToDomain($response->json(), CustomerResponse::class);
    }

    /**
     * Get the total count of NON-TRADE customers.
     *
     * Returns count of customers where trade=0 (or trade filter omitted).
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function getNonTradeCustomerCount(): int
    {
        $response = $this->transport->get(self::ENDPOINT_CUSTOMERS . '/count');

        return self::parseCountResponse($response->json());
    }

    /**
     * Get the total count of TRADE customers only.
     *
     * Returns count of customers where trade=1.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function getTradeCustomerCount(): int
    {
        $response = $this->transport->get(
            self::ENDPOINT_CUSTOMERS . '/count',
            ['trade' => '1'],
        );

        return self::parseCountResponse($response->json());
    }

    /**
     * Search for a customer by exact email match.
     *
     * Searches across BOTH trade and non-trade customers (no trade filter applied).
     *
     * WARNING: ShopWired's email search behaviour is not guaranteed to be an exact match.
     * This implementation verifies the returned customer's email matches the requested
     * email before returning. Returns null if no customer found or email doesn't match.
     *
     * @param string $email Email address to search for
     * @return DomainCustomer|null Customer if found with matching email, null otherwise
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function searchByEmail(string $email): ?DomainCustomer
    {
        $params = new CustomerQueryParams()
            ->withEmail($email)
            ->withBaseParams(
                new ShopwiredQueryParams()
                    ->withCount(1)
                    ->withEmbeds(self::DEFAULT_EMBEDS)
                    ->withFields(self::DEFAULT_FIELDS),
            );

        $response = $this->transport->get(
            self::ENDPOINT_CUSTOMERS,
            $params->toArray(),
        );

        $customers = self::parseArrayToDomain($response->json(), CustomerResponse::class);

        /** @var DomainCustomer|null $customer */
        $customer = $customers[0] ?? null;

        // Verify exact email match - ShopWired search may not be exact
        if (($customer !== null) && (\mb_strtolower($customer->email) !== \mb_strtolower($email))) {
            return null;
        }

        return $customer;
    }

    /**
     * Fetch a single page of customers.
     *
     * @return list<DomainCustomer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function fetchCustomerPage(CustomerQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT_CUSTOMERS,
            $params->toArray(),
        );

        /** @var list<DomainCustomer> */
        return self::parseArrayToDomain($response->json(), CustomerResponse::class);
    }
}
