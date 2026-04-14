<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

use App\Application\Contracts\Catalog\RelatedProductsAlgorithmParamsRepositoryInterface;
use App\Domain\Catalog\RelatedProducts\ValueObjects\RelatedProductsAlgorithmParams;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Catalog\Product\Models\RelatedProductsAlgorithmParamsModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Override;

final class RelatedProductsAlgorithmParamsRepository implements RelatedProductsAlgorithmParamsRepositoryInterface
{
    /** @var class-string<RelatedProductsAlgorithmParamsModel> */
    private const string MODEL_CLASS = RelatedProductsAlgorithmParamsModel::class;

    public function __construct(
        private readonly EloquentGateway $eloquentGateway,
    ) {}

    /**
     * @throws ResourceNotFoundException When no active params row exists
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function getActiveParams(): RelatedProductsAlgorithmParams
    {
        /** @var RelatedProductsAlgorithmParamsModel|null $model */
        $model = $this->eloquentGateway->query(
            static fn(): ?RelatedProductsAlgorithmParamsModel => self::MODEL_CLASS::query()
                ->where('is_active', true)
                ->first(),
        );

        if ($model === null) {
            throw new ResourceNotFoundException('catalog', 'RelatedProductsAlgorithmParams', 0);
        }

        return $model->toDomain();
    }
}
