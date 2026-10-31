<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Catalog\Results\ProductListPageResult;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Reads GET /api/products pages, pairing each product with its still-valid stored
 * fragment or, when none matches the live inputs' fingerprint, a freshly built view.
 */
interface ProductListReaderInterface
{
    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    public function page(ProductListQueryParams $query): ProductListPageResult;

    /**
     * Rebuilds entries for the given products from live inputs, never joining stored fragments: every entry's view is non-null.
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
    public function rebuild(array $productIds, array $includes): array;
}
