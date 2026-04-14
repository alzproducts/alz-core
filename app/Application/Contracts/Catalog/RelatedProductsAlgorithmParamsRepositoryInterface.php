<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Domain\Catalog\RelatedProducts\ValueObjects\RelatedProductsAlgorithmParams;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface RelatedProductsAlgorithmParamsRepositoryInterface
{
    /**
     * Fetch the currently active algorithm parameters.
     *
     * @throws ResourceNotFoundException When no active params row exists
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getActiveParams(): RelatedProductsAlgorithmParams;
}
