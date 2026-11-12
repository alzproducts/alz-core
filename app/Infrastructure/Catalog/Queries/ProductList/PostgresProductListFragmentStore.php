<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Application\Contracts\Catalog\ProductListFragmentStoreInterface;
use App\Application\Contracts\ErrorReporterInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;

/**
 * catalog.product_list_fragments: rendered list bodies keyed (product_id, shape), trusted by a
 * reader only while their fingerprint matches the live inputs.
 */
final readonly class PostgresProductListFragmentStore implements ProductListFragmentStoreInterface
{
    private const string TABLE = 'catalog.product_list_fragments';

    public function __construct(
        private DatabaseGateway $gateway,
        private ErrorReporterInterface $errorReporter,
    ) {}

    /**
     * Rows go in (product_id, shape) order so concurrent writers lock keys in the same order.
     *
     * @param list<ProductListFragmentDTO> $fragments
     */
    public function storeMany(array $fragments): void
    {
        $rows = self::uniqueSortedByKey($fragments);

        if ($rows === []) {
            return;
        }

        try {
            $this->gateway->runSql(self::upsertSql(\count($rows)), self::upsertBindings($rows));
        } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e) {
            // intentional: fail open like ResilientCache (ADR-0005); every read rechecks the fingerprint.
            $this->errorReporter->report($e, ['operation' => 'store_product_list_fragments', 'fragments' => \count($rows)]);
        }
    }

    /**
     * @return list<ProductListFragmentDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function sample(int $limit): array
    {
        $connection = $this->gateway->connection();
        $sql = 'SELECT product_id, shape, fingerprint, body FROM ' . self::TABLE . ' ORDER BY random() LIMIT ?';
        $rows = $this->gateway->query(static fn(): array => $connection->select($sql, [$limit]));

        return \array_values(\array_map(static function (mixed $raw): ProductListFragmentDTO {
            $row = ProductListValue::row($raw);

            return new ProductListFragmentDTO(
                productId: ProductListValue::string($row, 'product_id'),
                shape: ProductListValue::string($row, 'shape'),
                fingerprint: ProductListValue::string($row, 'fingerprint'),
                body: ProductListValue::string($row, 'body'),
            );
        }, $rows));
    }

    /**
     * @param list<array{productId: string, shape: string}> $keys
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteMany(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $tuples = \implode(', ', \array_fill(0, \count($keys), '(?::uuid, ?)'));
        $bindings = \array_merge(...\array_map(static fn(array $key): array => [$key['productId'], $key['shape']], $keys));

        $this->gateway->runSql('DELETE FROM ' . self::TABLE . " WHERE (product_id, shape) IN ({$tuples})", $bindings);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteOrphans(): int
    {
        return $this->gateway->runSql(
            'DELETE FROM ' . self::TABLE . ' f WHERE NOT EXISTS (SELECT 1 FROM shopwired.products p WHERE p.id = f.product_id)',
        );
    }

    /**
     * One row per key, the last fragment winning: ON CONFLICT cannot touch a row twice in one statement.
     *
     * @param list<ProductListFragmentDTO> $fragments
     *
     * @return list<ProductListFragmentDTO>
     */
    private static function uniqueSortedByKey(array $fragments): array
    {
        $byKey = [];

        foreach ($fragments as $fragment) {
            $byKey[$fragment->productId . "\0" . $fragment->shape] = $fragment;
        }

        \ksort($byKey, \SORT_STRING);

        return \array_values($byKey);
    }

    private static function upsertSql(int $rowCount): string
    {
        return 'INSERT INTO ' . self::TABLE . ' (product_id, shape, fingerprint, body, built_at) VALUES '
            . \implode(', ', \array_fill(0, $rowCount, '(?::uuid, ?, ?, ?, now())'))
            . ' ON CONFLICT (product_id, shape) DO UPDATE'
            . ' SET fingerprint = EXCLUDED.fingerprint, body = EXCLUDED.body, built_at = EXCLUDED.built_at';
    }

    /**
     * @param list<ProductListFragmentDTO> $rows
     *
     * @return list<string>
     */
    private static function upsertBindings(array $rows): array
    {
        $bindings = [];

        foreach ($rows as $row) {
            \array_push($bindings, $row->productId, $row->shape, $row->fingerprint, $row->body);
        }

        return $bindings;
    }
}
