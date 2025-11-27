<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Domain\Catalog\ValueObjects\Category as DomainCategory;
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

    public function __construct(
        private ShopwiredHttpTransport $transport,
    ) {}

    /**
     * List ALL categories with embedded parents (paginated fetch).
     *
     * @return list<DomainCategory>
     */
    public function listAllCategories(): array
    {
        $params = ShopwiredQueryParams::forBulkFetch()->withEmbeds(['parents']);

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

        $dtos = self::parseArrayResponse($response->json(), Category::class);

        $result = [];
        foreach ($dtos as $dto) {
            $result[] = $dto->toDomain();
        }

        return $result;
    }

    public function getCategoryById(int $id): DomainCategory
    {
        $response = $this->transport->get(self::ENDPOINT_CATEGORIES . '/' . $id);

        /** @var Category $dto */
        $dto = self::parseSingleResponse($response->json(), Category::class);

        return $dto->toDomain();
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

        $dtos = self::parseArrayResponse($response->json(), Category::class);

        $result = [];
        foreach ($dtos as $dto) {
            $result[] = $dto->toDomain();
        }

        return $result;
    }
}
