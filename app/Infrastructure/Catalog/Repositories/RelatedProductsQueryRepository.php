<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Contracts\Catalog\RelatedProductsQueryRepositoryInterface;
use App\Domain\Catalog\RelatedProducts\ValueObjects\RelatedProductsAlgorithmParams;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

/**
 * Runs the related products algorithm SQL and returns the computed desired state.
 *
 * The algorithm SQL is split across RelatedProductsAlgorithmSql fragment methods
 * and assembled here. See that class for the SQL decomposition rationale.
 */
final class RelatedProductsQueryRepository implements RelatedProductsQueryRepositoryInterface
{
    /** @var class-string<ProductModel> */
    private const string MODEL_CLASS = ProductModel::class;

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
    public function computeRelatedProducts(RelatedProductsAlgorithmParams $params): array
    {
        $bindings = [
            $params->categoryWeight,
            $params->titleWeight,
            $params->popularityWeight,
            $params->maxResults,
            $params->minContentScore,
            $params->defaultPopularity,
            $params->excludeCompareList,
        ];

        /** @var list<object{product_external_id: int, related_external_id: int, position: int}> $rows */
        $rows = $this->eloquentGateway->query(static fn(): array => self::MODEL_CLASS::query()
            ->getConnection()
            ->select(RelatedProductsAlgorithmSql::buildSql(), $bindings));

        return self::groupByProduct($rows);
    }

    /**
     * @param  list<object{product_external_id: int, related_external_id: int, position: int}>  $rows
     * @return array<int, list<IntId>>
     */
    private static function groupByProduct(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[$row->product_external_id][] = IntId::fromTrusted($row->related_external_id);
        }

        return $result;
    }
}
