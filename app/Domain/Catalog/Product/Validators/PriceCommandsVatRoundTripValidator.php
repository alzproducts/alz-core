<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Shared\Money\Validators\VatRoundTripAggregateResult;
use App\Domain\Shared\Money\Validators\VatRoundTripResult;
use App\Domain\Shared\Money\Validators\VatRoundTripValidator;
use App\Domain\Shared\Money\ValueObjects\Money;
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
                $results["{$command->sku->value}.price"] = $this->runFieldValidation($command->sku->value, 'price', $command->price);
            }

            if ($command->salePrice !== null && ! $command->salePrice->isZero()) {
                $results["{$command->sku->value}.salePrice"] = $this->runFieldValidation($command->sku->value, 'salePrice', $command->salePrice);
            }
        }

        return new VatRoundTripAggregateResult($results);
    }

    private function runFieldValidation(string $sku, string $field, Money $amount): VatRoundTripResult
    {
        return (new VatRoundTripValidator(
            grossAmount: $amount->toGross(),
            sku: $sku,
            field: $field,
            taxRate: $this->taxRate,
        ))->validate();
    }
}
