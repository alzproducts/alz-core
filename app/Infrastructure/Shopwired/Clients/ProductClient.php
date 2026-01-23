<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Factories\ProductDomainFactory;
use App\Infrastructure\Shopwired\Responses\ProductResponse;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use App\Infrastructure\Shopwired\ShopwiredPaginator;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use Generator;

/**
 * ShopWired Products API Client.
 *
 * Handles product retrieval operations from the ShopWired API.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
 *
 * NOTE: Unlike CustomerClient/OrderClient which use DTO.toDomain(), this client
 * uses ProductDomainFactory for the DTO→Domain transformation. This is because
 * Product custom fields require the CustomFieldDefinitionRegistry for typing.
 *
 * @see https://shopwired.readme.io/reference/listproducts
 */
final readonly class ProductClient implements ProductClientInterface
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT_PRODUCTS = 'products';

    /**
     * Embeds for product requests.
     *
     * @var list<string>
     */
    private const array DEFAULT_EMBEDS = ['variations', 'images', 'categories', 'custom_fields', 'vat_relief'];

    /**
     * Fields for product requests.
     *
     * Must include 'customFields' (camelCase) when 'custom_fields' embed is used.
     * Without explicit fields, customFields data is not returned.
     *
     * @var list<string>
     */
    private const array DEFAULT_FIELDS = [
        'id',
        'sku',
        'gtin',
        'title',
        'description',
        'slug',
        'url',
        'price',
        'costPrice',
        'salePrice',
        'comparePrice',
        'stock',
        'active',
        'vatExclusive',
        'vatRelief',
        'weight',
        'metaTitle',
        'metaDescription',
        'createdAt',
        'updatedAt',
        'variations',
        'images',
        'categories',
        'customFields',
    ];

    /**
     * Minimal fields for lightweight ID-only fetches.
     *
     * @var list<string>
     */
    private const array ID_ONLY_FIELDS = ['id'];

    public function __construct(
        private ShopwiredHttpTransport $transport,
        private ProductDomainFactory $factory,
    ) {}

    /**
     * List all products with full embedded data (paginated fetch).
     *
     * Fetches all pages automatically, loading into memory.
     * For large catalogs (~1,500+ products), prefer iterateProductBatches().
     *
     * @return list<Product>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllProducts(): array
    {
        $params = ShopwiredQueryParams::forBulkFetch()
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withFields(self::DEFAULT_FIELDS);

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(ShopwiredQueryParams $p): array => $this->fetchProductPage($p),
            knownTotal: $this->getProductCount(),
        );
    }

    /**
     * Iterate products in batches (memory-efficient).
     *
     * Yields batches of ~100 products per page, allowing the caller to process
     * and discard each batch before fetching the next. Use for syncing large catalogs.
     *
     * @return Generator<int, list<Product>, mixed, void> Yields batches of products (page number as key)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateProductBatches(): Generator
    {
        $params = ShopwiredQueryParams::forBulkFetch()
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withFields(self::DEFAULT_FIELDS);

        yield from ShopwiredPaginator::pages(
            params: $params,
            fetchPage: fn(ShopwiredQueryParams $p): array => $this->fetchProductPage($p),
            knownTotal: $this->getProductCount(),
        );
    }

    /**
     * Get a single product by its ShopWired ID.
     *
     * Includes full embedded data (variations, images, custom fields).
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When product not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getProductById(int $id): Product
    {
        $params = (new ShopwiredQueryParams())
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withFields(self::DEFAULT_FIELDS);

        $response = $this->transport->get(
            self::ENDPOINT_PRODUCTS . '/' . $id,
            $params->toArray(),
        );

        /** @var ProductResponse $dto */
        $dto = self::parseSingleResponse($response->json(), ProductResponse::class);

        return $this->factory->fromResponse($dto);
    }

    /**
     * Get the total count of products.
     *
     * Useful for progress tracking during sync operations.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getProductCount(): int
    {
        $response = $this->transport->get(self::ENDPOINT_PRODUCTS . '/count');

        return self::parseCountResponse($response->json());
    }

    /**
     * Get all product external IDs (lightweight).
     *
     * Returns only the ShopWired product IDs without full product data.
     * Use for reconciliation to identify orphaned local records.
     *
     * @return list<int> ShopWired product IDs
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getAllProductIds(): array
    {
        $params = ShopwiredQueryParams::forBulkFetch()
            ->withFields(self::ID_ONLY_FIELDS);

        $allIds = [];
        $currentParams = $params;

        do {
            $response = $this->transport->get(
                self::ENDPOINT_PRODUCTS,
                $currentParams->toArray(),
            );

            /** @var mixed $items */
            $items = $response->json();

            if (! \is_array($items)) {
                break;
            }

            foreach ($items as $item) {
                if (\is_array($item) && isset($item['id']) && \is_int($item['id'])) {
                    $allIds[] = $item['id'];
                }
            }

            // Stop if we got fewer items than requested (final page)
            if (\count($items) < $currentParams->getCount()) {
                break;
            }

            $currentParams = $currentParams->nextPage();
        } while (true);

        return $allIds;
    }

    /**
     * Fetch a single page of products.
     *
     * @return list<Product>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    private function fetchProductPage(ShopwiredQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT_PRODUCTS,
            $params->toArray(),
        );

        $dtos = self::parseArrayResponse($response->json(), ProductResponse::class);

        $products = [];
        foreach ($dtos as $dto) {
            /** @var ProductResponse $dto */
            $products[] = $this->factory->fromResponse($dto);
        }

        return $products;
    }
}
