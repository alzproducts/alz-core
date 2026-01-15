<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Domain\Customer\ValueObjects\Customer as DomainCustomer;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Infrastructure\Shopwired\CustomerQueryParams;
use App\Infrastructure\Shopwired\Enums\CustomerSort;
use App\Infrastructure\Shopwired\Responses\CustomerResponse;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
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
        private ShopwiredHttpTransport $transport,
    ) {}

    /**
     * List all NON-TRADE customers with embedded data (paginated fetch).
     *
     * Uses created_desc (newest first) so recent customers are prioritized if sync fails mid-way.
     * Non-trade customers only (trade=0 / trade filter omitted).
     *
     * @return list<DomainCustomer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllNonTradeCustomers(): array
    {
        $params = CustomerQueryParams::forBulkFetch()
            ->withSort(CustomerSort::CreatedDesc)
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DEFAULT_EMBEDS)
                    ->withFields(self::DEFAULT_FIELDS),
            );

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(CustomerQueryParams $p): array => $this->fetchCustomerPage($p),
            knownTotal: $this->getNonTradeCustomerCount(),
        );
    }

    /**
     * List all TRADE customers with embedded data (paginated fetch).
     *
     * Trade customers only (trade=1). Uses created_desc (newest first).
     *
     * @return list<DomainCustomer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllTradeCustomers(): array
    {
        $params = CustomerQueryParams::forBulkFetch()
            ->withTrade(true)
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DEFAULT_EMBEDS)
                    ->withFields(self::DEFAULT_FIELDS),
            );

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(CustomerQueryParams $p): array => $this->fetchCustomerPage($p),
            knownTotal: $this->getTradeCustomerCount(),
        );
    }

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
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateNonTradeCustomerBatches(): Generator
    {
        $params = CustomerQueryParams::forBulkFetch()
            ->withSort(CustomerSort::CreatedDesc)
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DEFAULT_EMBEDS)
                    ->withFields(self::DEFAULT_FIELDS),
            );

        yield from ShopwiredPaginator::pages(
            params: $params,
            fetchPage: fn(CustomerQueryParams $p): array => $this->fetchCustomerPage($p),
            knownTotal: $this->getNonTradeCustomerCount(),
        );
    }

    /**
     * Iterate customers in batches (memory-efficient).
     *
     * Always yields ALL trade customers first (B2B priority, ~5 pages), then non-trade
     * customers (newest first). If $maxNonTradePages is null, yields all non-trade
     * pages (~677 pages); otherwise limits to specified number of pages.
     *
     * Page numbers are sequential across both passes (trade pages 1-N, non-trade N+1-M).
     *
     * @param int|null $maxNonTradePages Max non-trade pages (null = all, 1 page ≈ 100 customers)
     *
     * @return Generator<int, list<DomainCustomer>, mixed, void> Yields batches (page number as key)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateCustomerBatches(?int $maxNonTradePages = null): Generator
    {
        $pageNumber = 0;

        // First pass: ALL trade customers (priority B2B accounts, ~5 pages)
        foreach ($this->iterateTradeCustomerBatches() as $batch) {
            $pageNumber++;
            yield $pageNumber => $batch;
        }

        // Second pass: non-trade customers (bulk B2C, limited or all)
        $nonTradePageCount = 0;
        foreach ($this->iterateNonTradeCustomerBatches() as $batch) {
            $pageNumber++;
            $nonTradePageCount++;
            yield $pageNumber => $batch;

            // Stop if we've reached the page limit (null = no limit)
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
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function iterateTradeCustomerBatches(): Generator
    {
        $params = CustomerQueryParams::forBulkFetch()
            ->withTrade(true)
            ->withSort(CustomerSort::CreatedDesc)
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DEFAULT_EMBEDS)
                    ->withFields(self::DEFAULT_FIELDS),
            );

        yield from ShopwiredPaginator::pages(
            params: $params,
            fetchPage: fn(CustomerQueryParams $p): array => $this->fetchCustomerPage($p),
            knownTotal: $this->getTradeCustomerCount(),
        );
    }

    /**
     * List non-trade customers (single page, default parameters).
     *
     * Returns first page of non-trade customers without embeds or custom fields.
     *
     * @return list<DomainCustomer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listNonTradeCustomers(): array
    {
        $response = $this->transport->get(self::ENDPOINT_CUSTOMERS);

        /** @var list<DomainCustomer> */
        return self::parseArrayToDomain($response->json(), CustomerResponse::class);
    }

    /**
     * Get a single customer by ID.
     *
     * Works for both trade and non-trade customers — the ID is globally unique.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When customer not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getCustomerById(int $id): DomainCustomer
    {
        $response = $this->transport->get(self::ENDPOINT_CUSTOMERS . '/' . $id);

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
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getNonTradeCustomerCount(): int
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
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getTradeCustomerCount(): int
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
     * @throws ResourceNotFoundException When resource not found (404)
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
     * @throws ResourceNotFoundException When resource not found (404)
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
