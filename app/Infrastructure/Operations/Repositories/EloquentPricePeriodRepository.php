<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Repositories;

use App\Application\Contracts\Operations\PricePeriodRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Operations\Models\PricePeriodModel;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of SCD2 price period repository.
 *
 * Uses DatabaseGateway for transactional writes. Each price change:
 * 1. Closes the current period (UPDATE effective_to = now())
 * 2. Inserts a new period with the given pricing
 *
 * Both operations share the same timestamp for consistency.
 */
final readonly class EloquentPricePeriodRepository implements PricePeriodRepositoryInterface
{
    public function __construct(
        private DatabaseGateway $gateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function recordPriceChange(
        string $sku,
        float $basePriceGross,
        ?float $salePriceGross,
        float $effectivePriceGross,
        bool $priceHasTax,
    ): void {
        $now = CarbonImmutable::now();

        $this->gateway->transact(
            static function () use ($sku, $basePriceGross, $salePriceGross, $effectivePriceGross, $priceHasTax, $now): void {
                // Close the current period (no-op if no current period exists)
                PricePeriodModel::query()
                    ->where('sku', $sku)
                    ->whereNull('effective_to')
                    ->update(['effective_to' => $now]);

                // Insert new period
                $model = new PricePeriodModel();
                $model->sku = $sku;
                $model->base_price_gross = $basePriceGross;
                $model->sale_price_gross = $salePriceGross;
                $model->effective_price_gross = $effectivePriceGross;
                $model->price_has_tax = $priceHasTax;
                $model->effective_from = $now;
                $model->save();
            },
        );
    }
}
