<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Catalog\Results\ProductListPageResult;
use App\Application\Contracts\Catalog\ProductListReaderInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Webmozart\Assert\Assert;

/**
 * Pages GET /api/products through {@see ProductListSql}: each product comes back with its stored
 * fragment when the fingerprint of its live inputs still matches, otherwise as a freshly built view.
 */
final readonly class ProductListQueryRepository implements ProductListReaderInterface
{
    private const string VIEW = 'catalog.products_view';

    public function __construct(
        private DatabaseGateway $gateway,
        private string $release,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    public function page(ProductListQueryParams $query): ProductListPageResult
    {
        $shape = ProductListFragmentFormat::shape($query->includes);
        $connection = $this->gateway->connection();
        $scope = $connection->table(self::VIEW);
        ProductListScope::apply($scope, $query);

        $perPage = $query->pagination->perPage;
        $page = self::pageSelect($scope)->limit($perPage)->offset(($query->pagination->page - 1) * $perPage);
        $statement = ProductListSql::statement($page->toSql(), ProductListSql::FRAGMENT_JOIN);
        $bindings = [...$page->getBindings(), ProductListFragmentFormat::salt($this->release, $shape), $shape];

        [$total, $rows, $shared] = ProductListSnapshot::read($this->gateway, static fn(): array => [
            (clone $scope)->reorder()->count(),
            ...self::readRows($connection, $statement, $bindings),
        ]);

        return new ProductListPageResult(
            total: $total,
            perPage: $perPage,
            currentPage: $query->pagination->page,
            shape: $shape,
            entries: self::entries($rows, $shared, $query->includes),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param list<string> $productIds
     * @param list<ProductInclude> $includes
     *
     * @return list<ProductListEntryDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    public function rebuild(array $productIds, array $includes): array
    {
        if ($productIds === []) {
            return [];
        }

        Assert::allUuid($productIds, 'Product list rebuild takes product uuids');
        $connection = $this->gateway->connection();
        $page = self::pageSelect($connection->table(self::VIEW)->orderBy('id'))
            ->whereRaw('id = ANY(?::uuid[])', ['{' . \implode(',', $productIds) . '}']);
        $statement = ProductListSql::statement($page->toSql(), ProductListSql::NO_FRAGMENT_JOIN);
        $bindings = [...$page->getBindings(), ProductListFragmentFormat::salt($this->release, ProductListFragmentFormat::shape($includes))];

        [$rows, $shared] = ProductListSnapshot::read($this->gateway, static fn(): array => self::readRows($connection, $statement, $bindings));

        return self::entries($rows, $shared, $includes);
    }

    /**
     * Runs the list statement, then reads the shared inputs only when a row missed its fragment.
     * Called inside the snapshot: the shared inputs must be the ones the fingerprints hashed.
     *
     * @param array<mixed> $bindings
     *
     * @return array{array<mixed>, ?SharedListInputs}
     */
    private static function readRows(ConnectionInterface $connection, string $statement, array $bindings): array
    {
        $rows = $connection->select($statement, $bindings);
        $hasMiss = \array_any(
            $rows,
            static fn(mixed $row): bool => ProductListValue::nullableString(ProductListValue::row($row), 'cached_body') === null,
        );

        if (! $hasMiss) {
            return [$rows, null];
        }

        $shared = ProductListValue::row($connection->selectOne(ProductListSql::sharedInputsStatement()));

        return [$rows, SharedListInputs::fromJson(ProductListValue::string($shared, 'shared_inputs'))];
    }

    /**
     * The scoped page query selecting {@see ProductListSql::PAGE_COLUMNS}, with the scope's own
     * ORDER BY carried into `page_pos` so the statement can return rows in page order.
     */
    private static function pageSelect(Builder $scope): Builder
    {
        Assert::notEmpty($scope->orders, 'The products list page must be ordered');

        $orders = $scope->newQuery()->from(self::VIEW);
        $orders->orders = $scope->orders;
        $orderBindings = $scope->getRawBindings()['order'];
        $orders->setBindings($orderBindings, 'order');

        $prefix = (clone $orders)->reorder()->toSql() . ' order by ';
        $orderSql = $orders->toSql();
        Assert::startsWith($orderSql, $prefix, 'Unexpected ORDER BY rendering for the products list page');

        // intentional: selectRaw only takes a literal-string; the scope's ORDER BY is rendered SQL.
        return (clone $scope)
            ->select(ProductListSql::PAGE_COLUMNS)
            ->selectSub('row_number() over (order by ' . \mb_substr($orderSql, \mb_strlen($prefix)) . ')', 'page_pos')
            ->addBinding($orderBindings, 'select');
    }

    /**
     * @param array<mixed> $rows
     * @param list<ProductInclude> $includes
     *
     * @return list<ProductListEntryDTO>
     *
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    private static function entries(array $rows, ?SharedListInputs $shared, array $includes): array
    {
        $entries = [];

        foreach ($rows as $raw) {
            $entries[] = self::entry(ProductListValue::row($raw), $shared, $includes);
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<ProductInclude> $includes
     *
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    private static function entry(array $row, ?SharedListInputs $shared, array $includes): ProductListEntryDTO
    {
        $productId = ProductListValue::string($row, 'product_id');
        $fingerprint = ProductListValue::string($row, 'fingerprint');
        $cachedBody = ProductListValue::nullableString($row, 'cached_body');

        if ($cachedBody !== null) {
            return new ProductListEntryDTO($productId, $fingerprint, $cachedBody, null);
        }

        Assert::notNull($shared, 'A product list miss needs the shared inputs');
        $inputs = new ProductListInputRow(ProductListValue::decode(ProductListValue::string($row, 'inputs')));

        return new ProductListEntryDTO($productId, $fingerprint, null, ProductListViewAssembler::toViewDomain($inputs, $shared, $includes));
    }
}
