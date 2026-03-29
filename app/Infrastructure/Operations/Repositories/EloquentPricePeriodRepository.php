<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Repositories;

use App\Application\Contracts\Operations\PricePeriodRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Operations\ValueObjects\PriceSnapshot;
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
    public function recordPriceChange(PriceSnapshot $snapshot): void
    {
        $now = CarbonImmutable::now();

        $this->gateway->transact(
            static function () use ($snapshot, $now): void {
                // Close the current period (no-op if no current period exists)
                PricePeriodModel::query()
                    ->where('sku', $snapshot->sku->value)
                    ->whereNull('effective_to')
                    ->update(['effective_to' => $now]);

                // Insert new period
                $model = new PricePeriodModel();
                $model->sku = $snapshot->sku->value;
                $model->base_price_gross = $snapshot->basePriceGross;
                $model->sale_price_gross = $snapshot->salePriceGross;
                $model->effective_price_gross = $snapshot->effectivePriceGross;
                $model->price_has_tax = $snapshot->priceHasTax;
                $model->effective_from = $now;
                $model->save();
            },
        );
    }
}
