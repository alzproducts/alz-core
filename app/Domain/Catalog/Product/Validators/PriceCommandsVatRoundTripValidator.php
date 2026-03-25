<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Shared\Money\Validators\VatRoundTripAggregateResult;
use App\Domain\Shared\Money\Validators\VatRoundTripResult;
use App\Domain\Shared\Money\Validators\VatRoundTripValidator;
use App\Domain\Shared\Validation\Contracts\ValidatorInterface;
use App\Domain\ValueObjects\TaxRate;

/**
 * Validates that all prices in a batch of update commands survive VAT round-trip.
 *
 * Composes per-field VatRoundTripValidator for each non-null price and sale price,
 * returning an aggregate result keyed by "{sku}.{field}".
 */
final readonly class PriceCommandsVatRoundTripValidator implements ValidatorInterface
{
    /**
     * @param list<UpdatePriceCommand> $commands
     */
    public function __construct(
        private array $commands,
        private TaxRate $taxRate,
    ) {}

    public function validate(): VatRoundTripAggregateResult
    {
        /** @var array<string, VatRoundTripResult> $results */
        $results = [];

        foreach ($this->commands as $command) {
            if ($command->price !== null) {
                $key = "{$command->sku->value}.price";
                $results[$key] = (new VatRoundTripValidator(
                    grossAmount: $command->price->toGross(),
                    sku: $command->sku->value,
                    field: 'price',
                    taxRate: $this->taxRate,
                ))->validate();
            }

            if ($command->salePrice !== null && ! $command->salePrice->isZero()) {
                $key = "{$command->sku->value}.salePrice";
                $results[$key] = (new VatRoundTripValidator(
                    grossAmount: $command->salePrice->toGross(),
                    sku: $command->sku->value,
                    field: 'salePrice',
                    taxRate: $this->taxRate,
                ))->validate();
            }
        }

        return new VatRoundTripAggregateResult($results);
    }
}
