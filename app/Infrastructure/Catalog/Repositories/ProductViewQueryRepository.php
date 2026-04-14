<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Catalog\Product\Models\ProductViewModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

/**
 * Read-model queries against catalog.products_view via ProductViewModel.
 */
final class ProductViewQueryRepository implements ProductViewQueryRepositoryInterface
{
    /** @var class-string<ProductViewModel> */
    private const string MODEL_CLASS = ProductViewModel::class;

    public function __construct(
        private readonly EloquentGateway $eloquentGateway,
    ) {}

    /**
     * @return array<int, list<IntId>>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function getCurrentRelatedProducts(): array
    {
        /** @var list<ProductViewModel> $products */
        $products = $this->eloquentGateway->query(
            static fn(): array => self::MODEL_CLASS::query()
                ->where('is_active', true)
                ->whereJsonbIsArray('custom_fields', 'related_products')
                ->get(['external_id', 'custom_fields'])
                ->all(),
        );

        return self::parseProducts($products);
    }

    /**
     * @param  list<ProductViewModel>  $products
     * @return array<int, list<IntId>>
     */
    private static function parseProducts(array $products): array
    {
        $result = [];

        foreach ($products as $product) {
            /** @var array<string, mixed> $customFields */
            $customFields = $product->custom_fields;
            /** @var list<int> $ids */
            $ids = $customFields['related_products'] ?? [];

            $result[$product->external_id] = \array_map(
                static fn(int $id): IntId => IntId::fromTrusted($id),
                $ids,
            );
        }

        return $result;
    }
}
