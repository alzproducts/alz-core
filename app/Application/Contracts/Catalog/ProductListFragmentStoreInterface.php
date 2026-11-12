<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Persistence for rendered product list fragments.
 */
interface ProductListFragmentStoreInterface
{
    /**
     * Never throws: a failed write is reported and dropped, because every read rechecks the fingerprint.
     *
     * @param list<ProductListFragmentDTO> $fragments
     */
    public function storeMany(array $fragments): void;

    /**
     * @return list<ProductListFragmentDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function sample(int $limit): array;

    /**
     * @param list<array{productId: string, shape: string}> $keys
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteMany(array $keys): void;

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteOrphans(): int;
}
