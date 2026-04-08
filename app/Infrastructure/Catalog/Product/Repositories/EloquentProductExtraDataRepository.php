<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Repositories;

use App\Application\Contracts\Catalog\ProductExtraDataRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\Catalog\Product\Models\ProductExtraDataModel;

/**
 * Eloquent implementation of per-SKU extra data repository.
 */
final readonly class EloquentProductExtraDataRepository implements ProductExtraDataRepositoryInterface
{
    public function __construct(
        private DatabaseGatewayInterface $gateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function upsertRrp(Sku $sku, ?Money $rrp): void
    {
        $this->gateway->transact(static function () use ($sku, $rrp): void {
            ProductExtraDataModel::updateOrCreate(
                ['sku' => $sku->value],
                ['rrp' => $rrp?->toGross()],
            );
        });
    }
}
