<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Domain\Catalog\ValueObjects\Category as DomainCategory;
use App\Infrastructure\Shopwired\Enums\CategorySort;
use App\Infrastructure\Shopwired\Responses\Category;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
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
        'customFields',
    ];

    public function __construct(
        private ShopwiredHttpTransport $transport,
    ) {}

    /**
     * List ALL categories with embedded parents and custom fields (paginated fetch).
     *
     * @param CategorySort|null $sort Sort order (default: API default)
     *
     * @return list<DomainCategory>
     */
    public function listAllCategories(?CategorySort $sort = null): array
    {
        $params = ShopwiredQueryParams::forBulkFetch()
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withFields(self::DEFAULT_FIELDS)
            ->withSort($sort?->value);

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(ShopwiredQueryParams $p): array => $this->fetchCategoryPage($p),
        );
    }

    /**
     * @return list<DomainCategory>
     */
    public function listCategories(): array
    {
        $response = $this->transport->get(self::ENDPOINT_CATEGORIES);

        /** @var list<DomainCategory> */
        return self::parseArrayToDomain($response->json(), Category::class);
    }

    public function getCategoryById(int $id): DomainCategory
    {
        $response = $this->transport->get(self::ENDPOINT_CATEGORIES . '/' . $id);

        /** @var DomainCategory */
        return self::parseSingleToDomain($response->json(), Category::class);
    }

    public function getCategoryCount(): int
    {
        $response = $this->transport->get(self::ENDPOINT_CATEGORIES . '/count');

        return self::parseCountResponse($response->json());
    }

    /**
     * Fetch a single page of categories.
     *
     * @return list<DomainCategory>
     */
    private function fetchCategoryPage(ShopwiredQueryParams $params): array
    {
        $response = $this->transport->get(
            self::ENDPOINT_CATEGORIES,
            $params->toArray(),
        );

        /** @var list<DomainCategory> */
        return self::parseArrayToDomain($response->json(), Category::class);
    }
}
