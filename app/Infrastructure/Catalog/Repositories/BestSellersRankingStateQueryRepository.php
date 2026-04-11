<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Contracts\Catalog\BestSellersRankingStateQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

final class BestSellersRankingStateQueryRepository implements BestSellersRankingStateQueryRepositoryInterface
{
    /** @var class-string<ProductModel> */
    private const string MODEL_CLASS = ProductModel::class;

    /**
     * Minimum final_score of a genuine seller. Non-sellers are pinned to
     * exactly 1.00 in the snapshot, so this threshold excludes dead stock.
     */
    private const float MIN_SELLER_SCORE = 2.00;

    public function __construct(
        private readonly EloquentGateway $eloquentGateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function findTopRankedProductIds(int $limit): array
    {
        /** @var list<object{parent_external_id: int}> $rows */
        $rows = $this->eloquentGateway->query(static fn(): array => self::MODEL_CLASS::query()
            ->getConnection()
            ->select(
                <<<'SQL'
                SELECT parent_external_id
                FROM catalog.product_popularity_ranking_latest
                WHERE final_score >= ?
                ORDER BY final_score DESC
                LIMIT ?
                SQL,
                [self::MIN_SELLER_SCORE, $limit],
            ));

        return \array_map(static fn(object $row): int => $row->parent_external_id, $rows);
    }
}
