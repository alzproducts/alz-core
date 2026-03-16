<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Domain\Catalog\ValueObjects\Brand as DomainBrand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Responses\BrandResponse;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Brands API Client.
 *
 * Handles brand retrieval operations from the ShopWired API.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
 *
 * @see https://shopwired.readme.io/docs/brands
 */
final readonly class BrandClient implements BrandClientInterface
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT_BRANDS = 'brands';

    /**
     * Default embeds for brand requests.
     *
     * @var list<string>
     */
    private const array DEFAULT_EMBEDS = ['custom_fields'];

    /**
     * Default fields for brand requests.
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
        'slug',
        'url',
        'active',
        'featured',
        'sortOrder',
        'metaTitle',
        'metaKeywords',
        'metaDescription',
        'image',
        'customFields',
    ];

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * List ALL brands with embedded custom fields (paginated fetch).
     *
     * @return list<DomainBrand>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllBrands(): array
    {
        $params = ShopwiredQueryParams::forBulkFetch()
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withFields(self::DEFAULT_FIELDS);

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(ShopwiredQueryParams $p): array => $this->fetchBrandPage($p),
        );
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When brand not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getBrandById(int $id): DomainBrand
    {
        $params = (new ShopwiredQueryParams())
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withFields(self::DEFAULT_FIELDS);

        $response = $this->transport->get(self::ENDPOINT_BRANDS . '/' . $id, $params->toArray());

        /** @var DomainBrand */
        return self::parseSingleToDomain($response->json(), BrandResponse::class);
    }

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getBrandCount(): int
    {
        $response = $this->transport->get(self::ENDPOINT_BRANDS . '/count');

        return self::parseCountResponse($response->json());
    }

    /**
     * Fetch a single page of brands.
     *
     * @return list<DomainBrand>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function fetchBrandPage(ShopwiredQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT_BRANDS,
            $params->toArray(),
        );

        /** @var list<DomainBrand> */
        return self::parseArrayToDomain($response->json(), BrandResponse::class);
    }
}
