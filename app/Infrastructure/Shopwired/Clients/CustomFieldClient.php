<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CustomFieldClientInterface;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Responses\CustomFieldDefinitionResponse;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Custom Fields API Client.
 *
 * Fetches custom field definitions (schema/metadata) from the ShopWired API.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
 *
 * This is a simple endpoint with ~100-150 definitions total, requiring only
 * 2-3 API calls. No generator or batching needed - fetchAll() is sufficient.
 *
 * @see https://shopwired.readme.io/reference/listcustomfields
 */
final readonly class CustomFieldClient implements CustomFieldClientInterface
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT = 'custom-fields';

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * List all custom field definitions.
     *
     * @return list<CustomFieldDefinition>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails or no definitions returned
     */
    public function listAll(): array
    {
        $params = ShopwiredQueryParams::forBulkFetch();

        /** @var list<CustomFieldDefinition> $definitions */
        $definitions = ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(ShopwiredQueryParams $p): array => $this->fetchPage($p),
        );

        // Zero definitions is an error - ShopWired always has custom fields
        if ($definitions === []) {
            throw new InvalidApiResponseException(
                serviceName: 'Shopwired',
                message: 'Custom fields endpoint returned zero definitions. API may have changed.',
            );
        }

        return $definitions;
    }

    /**
     * Fetch a single page of custom field definitions.
     *
     * @return list<CustomFieldDefinition>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function fetchPage(ShopwiredQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT,
            $params->toArray(),
        );

        /** @var list<CustomFieldDefinition> */
        return self::parseArrayToDomain($response->json(), CustomFieldDefinitionResponse::class);
    }
}
