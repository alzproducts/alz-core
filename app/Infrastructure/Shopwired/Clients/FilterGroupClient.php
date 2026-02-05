<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\FilterGroupClientInterface;
use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Responses\FilterGroupDefinitionResponse;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Filter Groups API Client.
 *
 * Fetches filter group definitions from the ShopWired API.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
 *
 * Note: This endpoint is undocumented in official ShopWired API docs
 * but is fully functional. Filter groups are a small dataset (~10-20 groups).
 */
final readonly class FilterGroupClient implements FilterGroupClientInterface
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT = 'filter-groups';

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * List all filter group definitions.
     *
     * @return list<FilterGroupDefinition>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails
     */
    public function listAll(): array
    {
        $params = ShopwiredQueryParams::forBulkFetch();

        /** @var list<FilterGroupDefinition> */
        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(ShopwiredQueryParams $p): array => $this->fetchPage($p),
        );
    }

    /**
     * Fetch a single page of filter group definitions.
     *
     * @return list<FilterGroupDefinition>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function fetchPage(ShopwiredQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT,
            $params->toArray(),
        );

        /** @var list<FilterGroupDefinition> */
        return self::parseArrayToDomain($response->json(), FilterGroupDefinitionResponse::class);
    }
}
