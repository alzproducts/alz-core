<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Catalog\RelatedProducts\ValueObjects\RelatedProductsAlgorithmParams;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;

interface RelatedProductsQueryRepositoryInterface
{
    /**
     * Run the related products algorithm and return the computed desired state.
     *
     * @return array<int, list<IntId>> productExternalId → ordered list of related product IntIds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function computeRelatedProducts(RelatedProductsAlgorithmParams $params): array;
}
