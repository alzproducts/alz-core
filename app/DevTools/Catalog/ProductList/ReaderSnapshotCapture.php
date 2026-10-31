<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Catalog\Repositories\CreditTierDriftSql;
use App\Infrastructure\Catalog\Repositories\MarginTierDriftSql;
use App\Infrastructure\Catalog\Repositories\RelatedProductsAlgorithmParamsRepository;
use App\Infrastructure\Catalog\Repositories\RelatedProductsQueryRepository;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Shopwired\Repositories\EloquentProductRepository;
use RuntimeException;

/**
 * Captures the other readers of products_view and product_variations_view, each in a deterministic order.
 */
final readonly class ReaderSnapshotCapture
{
    private const string OFFERS_SQL = 'SELECT * FROM catalog.products_with_changed_offers_filters ORDER BY product_id, desired_filter_values::text';

    /** Both probes target the first detail product; each runs inside a transaction that is rolled back. */
    private const int PROBE_PRODUCT_ID = 2_010_000_000;

    /** The seed has no credit popularity snapshots; a latest-dated row makes one drift row. */
    private const string CREDIT_PROBE_SQL = <<<'SQL'
        INSERT INTO catalog.credit_product_popularity_snapshots (
            snapshot_date, algorithm_version, parent_external_id, calculated_sort_order, main_qty, main_turnover,
            recent_qty, recent_turnover, main_qty_rank, main_turnover_rank, recent_qty_rank, recent_turnover_rank,
            main_score, recent_score, final_score, trend, credit_tier
        ) VALUES ('2099-12-31', 1, ?, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 'bench-probe')
        SQL;

    /** The seed's sale filters all agree with sale state; both casings of "On Sale" can never be the desired value. */
    private const string OFFERS_PROBE_SQL = <<<'SQL'
        UPDATE shopwired.products
        SET filters = jsonb_set(COALESCE(filters, '{}'::jsonb), '{14}', '["on sale", "On Sale"]'::jsonb)
        WHERE external_id = ?
        SQL;

    public function __construct(
        private EloquentProductRepository $products,
        private DatabaseGateway $database,
        private RelatedProductsAlgorithmParamsRepository $relatedParams,
        private RelatedProductsQueryRepository $related,
    ) {}

    /**
     * @return array<string, mixed> Reader name => normalized result
     *
     * @throws RuntimeException
     */
    public function capture(): array
    {
        return [
            'reader-detail-product-views' => $this->detailViews(),
            'reader-margin-tier-drift' => $this->rows('SELECT * FROM (' . MarginTierDriftSql::SQL . ') AS drift ORDER BY external_id, target_label'),
            'reader-credit-tier-drift' => $this->probedRows(self::CREDIT_PROBE_SQL, 'SELECT * FROM (' . CreditTierDriftSql::SQL . ') AS drift ORDER BY external_id, target_label NULLS FIRST'),
            'reader-related-products' => $this->relatedProducts(),
            'reader-offers-filters' => $this->probedRows(self::OFFERS_PROBE_SQL, self::OFFERS_SQL),
        ];
    }

    /**
     * @return array<int, mixed> Product id => normalized view
     *
     * @throws RuntimeException
     */
    private function detailViews(): array
    {
        $views = [];

        foreach (ProductListRequestShapes::DETAIL_PRODUCT_IDS as $productId) {
            $view = $this->products->findDetailedProductView(IntId::from($productId), ProductInclude::cases());
            $views[$productId] = SnapshotValueNormalizer::normalize($view);
        }

        return $views;
    }

    /**
     * The whole related-products map, a superset of any fixed sample.
     *
     * @return array<int, list<int>> Product id => related product ids, in rank order
     *
     * @throws RuntimeException
     */
    private function relatedProducts(): array
    {
        $map = $this->related->computeRelatedProducts($this->relatedParams->getActiveParams());
        \ksort($map);

        return \array_map(
            static fn(array $relatedIds): array => \array_map(static fn(IntId $id): int => $id->value, $relatedIds),
            $map,
        );
    }

    /**
     * The reader's rows with one probe row mutated in, so the capture holds a non-empty result; nothing is committed.
     *
     * @return list<array<mixed>>
     *
     * @throws RuntimeException
     */
    private function probedRows(string $probeSql, string $sql): array
    {
        $connection = $this->database->connection();
        $connection->beginTransaction();

        try {
            $this->database->runSql($probeSql, [self::PROBE_PRODUCT_ID]);

            return $this->rows($sql);
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @return list<array<mixed>>
     *
     * @throws RuntimeException
     */
    private function rows(string $sql): array
    {
        /** @var list<object> $rows */
        $rows = $this->database->query(fn(): array => $this->database->connection()->select($sql));

        return \array_map(static fn(object $row): array => \get_object_vars($row), $rows);
    }
}
