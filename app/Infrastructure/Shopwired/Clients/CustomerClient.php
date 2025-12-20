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
use App\Infrastructure\Shopwired\Responses\CustomerResponse;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Customers API Client.
 *
 * Handles customer retrieval operations from the ShopWired API.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
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
        'creditEnabled',
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
     * List ALL customers with embedded data (paginated fetch).
     *
     * @return list<DomainCustomer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllCustomers(): array
    {
        $params = CustomerQueryParams::forBulkFetch()
            ->withBaseParams(
                ShopwiredQueryParams::forBulkFetch()
                    ->withEmbeds(self::DEFAULT_EMBEDS)
                    ->withFields(self::DEFAULT_FIELDS),
            );

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(CustomerQueryParams $p): array => $this->fetchCustomerPage($p),
            knownTotal: $this->getCustomerCount(),
        );
    }

    /**
     * List ALL trade customers with embedded data (paginated fetch).
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
     * @return list<DomainCustomer>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listCustomers(): array
    {
        $response = $this->transport->get(self::ENDPOINT_CUSTOMERS);

        /** @var list<DomainCustomer> */
        return self::parseArrayToDomain($response->json(), CustomerResponse::class);
    }

    /**
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
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getCustomerCount(): int
    {
        $response = $this->transport->get(self::ENDPOINT_CUSTOMERS . '/count');

        return self::parseCountResponse($response->json());
    }

    /**
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
     * Search for a customer by email.
     *
     * WARNING: ShopWired's email search behaviour is not guaranteed to be an exact match.
     * Callers MUST verify the returned customer's email matches the requested email
     * before using the result. Returns null if no customer found.
     *
     * @param string $email Email address to search for
     * @return DomainCustomer|null Customer if found (verify email match!), null otherwise
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
