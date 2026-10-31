<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use Closure;

/**
 * Runs the products list reads in one REPEATABLE READ READ ONLY snapshot, so the count and the
 * page, and `is_on_sale` with `has_any_sale`, come from the same commits.
 */
final class ProductListSnapshot
{
    /**
     * Inside an already-open transaction the reads run at its level: Postgres rejects
     * SET TRANSACTION once the transaction has run a query (SQLSTATE 25001).
     *
     * @template T
     *
     * @param Closure(): T $reads
     *
     * @return T
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public static function read(DatabaseGateway $gateway, Closure $reads): mixed
    {
        $connection = $gateway->connection();

        if ($connection->transactionLevel() > 0) {
            return $gateway->query($reads);
        }

        return $gateway->transact(static function () use ($connection, $reads): mixed {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $reads();
        });
    }
}
