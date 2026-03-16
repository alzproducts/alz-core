<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Domain\Catalog\ValueObjects\Category as DomainCategory;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Responses\CategoryResponse;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Categories API Client.
 *
 * Handles category retrieval operations from the ShopWired API.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
 *
 * @see https://shopwired.readme.io/docs/categories
 */
final readonly class CategoryClient implements CategoryClientInterface
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT_CATEGORIES = 'categories';

    /**
     * Default embeds for category requests.
     *
     * @var list<string>
     */
    private const array DEFAULT_EMBEDS = ['parents', 'custom_fields'];

    /**
     * Default fields for category requests.
     *
     * Must include 'customFields' (camelCase) when 'custom_fields' embed is used.
     * Without explicit fields, customFields data is not returned.
     *
     * @var list<string>
     */
    private const array DEFAULT_FIELDS = [
        'id',
        'createdAt',
        'title',
        'description',
        'description2',
        'slug',
        'url',
        'active',
        'featured',
        'tradeOnly',
        'sortOrder',
        'metaTitle',
        'metaKeywords',
        'metaDescription',
        'metaNoIndex',
        'image',
        'parents',
        'customFields',
    ];

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * List ALL categories with embedded parents and custom fields (paginated fetch).
     *
     * @return list<DomainCategory>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllCategories(): array
    {
        $params = ShopwiredQueryParams::forBulkFetch()
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withFields(self::DEFAULT_FIELDS);

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(ShopwiredQueryParams $p): array => $this->fetchCategoryPage($p),
        );
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getCategoryById(int $id): DomainCategory
    {
        $params = (new ShopwiredQueryParams())
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withFields(self::DEFAULT_FIELDS);

        $response = $this->transport->get(self::ENDPOINT_CATEGORIES . '/' . $id, $params->toArray());

        /** @var DomainCategory */
        return self::parseSingleToDomain($response->json(), CategoryResponse::class);
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getCategoryCount(): int
    {
        $response = $this->transport->get(self::ENDPOINT_CATEGORIES . '/count');

        return self::parseCountResponse($response->json());
    }

    /**
     * Fetch a single page of categories.
     *
     * @return list<DomainCategory>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function fetchCategoryPage(ShopwiredQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT_CATEGORIES,
            $params->toArray(),
        );

        /** @var list<DomainCategory> */
        return self::parseArrayToDomain($response->json(), CategoryResponse::class);
    }
}
