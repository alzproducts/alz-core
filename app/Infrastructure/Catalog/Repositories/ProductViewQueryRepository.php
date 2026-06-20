<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Catalog\BestSellerLabels\BestSellerLabelChangesResult;
use App\Application\Catalog\DTOs\CreditTierLabelChangeDTO;
use App\Application\Catalog\DTOs\MarginTierAssignmentDTO;
use App\Application\Catalog\Enums\BestSellerLabel;
use App\Application\Catalog\Enums\CreditTier;
use App\Application\Catalog\Enums\MarginTier;
use App\Application\Contracts\Catalog\ProductViewQueryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Catalog\Product\Models\ProductViewModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Webmozart\Assert\Assert;

/**
 * Read-model queries against catalog.products_view via ProductViewModel.
 */
final class ProductViewQueryRepository implements ProductViewQueryRepositoryInterface
{
    /** @var class-string<ProductViewModel> */
    private const string MODEL_CLASS = ProductViewModel::class;

    private const string LABEL_TEXT_PATH = "custom_fields->>'" . BestSellerLabel::FIELD . "'";

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
        return new BestSellerLabelChangesResult(
            toAdd: self::mapToIds($this->queryProductsNeedingLabel()),
            toRemove: self::mapToIds($this->queryProductsLosingLabel()),
        );
    }

    /**
     * @return list<MarginTierAssignmentDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function findMarginTierDrift(): array
    {
        /** @var list<object{external_id: int, target_label: string}> $rows */
        $rows = $this->eloquentGateway->query(
            static fn(): array => self::MODEL_CLASS::query()
                ->getConnection()
                ->select(MarginTierDriftSql::SQL),
        );

        return \array_map(
            static function (object $row): MarginTierAssignmentDTO {
                $tier = MarginTier::tryFrom($row->target_label);
                Assert::notNull($tier, 'SQL returned unrecognised margin tier label');

                return new MarginTierAssignmentDTO(
                    productId: IntId::fromTrusted($row->external_id),
                    targetLabel: $tier,
                );
            },
            $rows,
        );
    }

    /**
     * @return list<CreditTierLabelChangeDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function findCreditTierChanges(): array
    {
        /** @var list<object{external_id: int, target_label: string|null}> $rows */
        $rows = $this->eloquentGateway->query(
            static fn(): array => self::MODEL_CLASS::query()
                ->getConnection()
                ->select(CreditTierDriftSql::SQL),
        );

        return \array_map(
            static function (object $row): CreditTierLabelChangeDTO {
                $tier = $row->target_label === null ? null : CreditTier::tryFrom($row->target_label);
                Assert::true(
                    $row->target_label === null || $tier !== null,
                    'SQL returned unrecognised credit tier label',
                );

                return new CreditTierLabelChangeDTO(
                    productId: IntId::fromTrusted($row->external_id),
                    targetTier: $tier,
                );
            },
            $rows,
        );
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function refreshMaterializedView(): void
    {
        $this->eloquentGateway->query(
            static fn(): bool => self::MODEL_CLASS::query()
                ->getConnection()
                ->statement('REFRESH MATERIALIZED VIEW CONCURRENTLY catalog.products_view'),
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
    private function queryProductsNeedingLabel(): array
    {
        /** @var list<ProductViewModel> */
        return $this->eloquentGateway->query(
            static fn(): array => self::MODEL_CLASS::query()
                ->whereNotNull('popularity_rank')
                ->where('popularity_rank', '<=', 2)
                ->where(static function (Builder $q): void {
                    $q->whereRaw(self::LABEL_TEXT_PATH . ' IS NULL')
                        ->orWhereRaw(self::LABEL_TEXT_PATH . ' != ?', [BestSellerLabel::BestSellers->value]);
                })
                ->get(['external_id'])
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
    private function queryProductsLosingLabel(): array
    {
        /** @var list<ProductViewModel> */
        return $this->eloquentGateway->query(
            static fn(): array => self::MODEL_CLASS::query()
                ->where(static fn(Builder $q): Builder => $q
                    ->whereNull('popularity_rank')
                    ->orWhere('popularity_rank', '>', 2))
                ->whereRaw(self::LABEL_TEXT_PATH . ' = ?', [BestSellerLabel::BestSellers->value])
                ->get(['external_id'])
                ->all(),
        );
    }

    /**
     * @param list<ProductViewModel> $rows
     * @return list<IntId>
     */
    private static function mapToIds(array $rows): array
    {
        return \array_map(
            static fn(ProductViewModel $row): IntId => IntId::fromTrusted($row->external_id),
            $rows,
        );
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
