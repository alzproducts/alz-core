<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Catalog\BestSellerLabels\BestSellerLabelChangesResult;
use App\Application\Catalog\BestSellerLabels\BestSellerLabelTransformer;
use App\Application\Catalog\BestSellerLabels\ProductLabelCandidateDTO;
use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Catalog\Product\Models\ProductViewModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Illuminate\Database\Eloquent\Builder;
use Override;

/**
 * Read-model queries against catalog.products_view via ProductViewModel.
 */
final class ProductViewQueryRepository implements ProductViewQueryRepositoryInterface
{
    /** @var class-string<ProductViewModel> */
    private const string MODEL_CLASS = ProductViewModel::class;

    private const array LABEL_COLUMNS = ['external_id', 'custom_fields'];

    private const string LABEL_JSONB_PATH = "custom_fields->'" . BestSellerLabelTransformer::FIELD . "'";

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
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function findBestSellerLabelChanges(): BestSellerLabelChangesResult
    {
        $jsonbContains = '["' . BestSellerLabelTransformer::LABEL . '"]';

        return new BestSellerLabelChangesResult(
            toAdd: self::mapToCandidates($this->queryProductsNeedingLabel($jsonbContains)),
            toRemove: self::mapToCandidates($this->queryProductsLosingLabel($jsonbContains)),
        );
    }

    /**
     * Products with popularity_rank <= 2 that do NOT yet have the Best Sellers label.
     *
     * @return list<ProductViewModel>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function queryProductsNeedingLabel(string $jsonbContains): array
    {
        /** @var list<ProductViewModel> */
        return $this->eloquentGateway->query(
            static fn(): array => self::MODEL_CLASS::query()
                ->whereNotNull('popularity_rank')
                ->where('popularity_rank', '<=', 2)
                ->where(static function (Builder $q) use ($jsonbContains): void {
                    $q->whereRaw(self::LABEL_JSONB_PATH . ' IS NULL')
                        ->orWhereRaw('NOT (' . self::LABEL_JSONB_PATH . ' @> ?::jsonb)', [$jsonbContains]);
                })
                ->get(self::LABEL_COLUMNS)
                ->all(),
        );
    }

    /**
     * Products outside rank threshold that still carry the Best Sellers label.
     *
     * @return list<ProductViewModel>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function queryProductsLosingLabel(string $jsonbContains): array
    {
        /** @var list<ProductViewModel> */
        return $this->eloquentGateway->query(
            static fn(): array => self::MODEL_CLASS::query()
                ->where(static fn(Builder $q): Builder => $q
                    ->whereNull('popularity_rank')
                    ->orWhere('popularity_rank', '>', 2))
                ->whereRaw(self::LABEL_JSONB_PATH . ' @> ?::jsonb', [$jsonbContains])
                ->get(self::LABEL_COLUMNS)
                ->all(),
        );
    }

    /**
     * @param list<ProductViewModel> $rows
     * @return list<ProductLabelCandidateDTO>
     */
    private static function mapToCandidates(array $rows): array
    {
        return \array_map(static function (ProductViewModel $row): ProductLabelCandidateDTO {
            /** @var array<string, mixed> $customFields */
            $customFields = $row->custom_fields;
            /** @var list<string> $labels */
            $labels = $customFields[BestSellerLabelTransformer::FIELD] ?? [];

            return new ProductLabelCandidateDTO(
                productId: IntId::fromTrusted($row->external_id),
                currentLabels: $labels,
            );
        }, $rows);
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
