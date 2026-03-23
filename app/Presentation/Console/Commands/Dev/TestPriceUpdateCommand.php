<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductPricesUseCase;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateMalformedStringException;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Test price update flow against the configured test product.
 *
 * Calls UpdateProductPricesUseCase directly, triggering all downstream
 * listeners (sale detection, Linnworks EPs, Slack notifications).
 */
final class TestPriceUpdateCommand extends Command
{
    protected $signature = 'dev:test-price-update
        {sale-price : Sale price to set (e.g. 7.99)}
        {--reason=Dev test sale : Sale reason string}
        {--end-date= : Optional sale end date (Y-m-d)}';

    protected $description = 'Set a sale price on the test product to trigger the full pricing event chain';

    /**
     * Dependencies resolved in handle() to avoid eager resolution during
     * artisan command discovery (which would require API keys in CI).
     */
    public function handle(UpdateProductPricesUseCase $useCase): int
    {
        /** @var string $sku */
        $sku = \config('shopwired.test_product.sku');
        /** @var string $salePriceArg */
        $salePriceArg = $this->argument('sale-price');
        $salePrice = (float) $salePriceArg;

        if ($salePrice <= 0) {
            $this->error('Sale price must be greater than 0.');

            return self::FAILURE;
        }

        /** @var string $reason */
        $reason = $this->option('reason');
        /** @var string|null $endDateStr */
        $endDateStr = $this->option('end-date');

        try {
            $endDate = $endDateStr !== null ? new DateTimeImmutable($endDateStr) : null;
        } catch (DateMalformedStringException) {
            $this->error("Invalid date format: {$endDateStr} (expected Y-m-d)");

            return self::FAILURE;
        }

        $saleSettings = new SaleSettings(
            saleReason: $reason,
            saleStartDate: new DateTimeImmutable(),
            saleEndDate: $endDate,
        );

        $this->info("Updating SKU {$sku} → sale price £{$salePriceArg}");

        try {
            $result = $useCase->execute(
                skuUpdates: [
                    new UpdatePriceCommand(
                        sku: Sku::fromTrusted($sku),
                        salePrice: Money::inclusive($salePrice),
                    ),
                ],
                saleSettings: $saleSettings,
            );

            $this->info("Done — {$result->succeeded} succeeded, " . \count($result->skipped) . ' skipped');

            if ($result->skipped !== []) {
                foreach ($result->skipped as $skip) {
                    $this->warn("  Skipped {$skip->sku->value}: {$skip->reason}");
                }
            }

            if ($result->permanentFailures !== []) {
                foreach ($result->permanentFailures as $failure) {
                    $this->error("  Failed: {$failure->error}");
                }

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (Throwable $e) { // @ignoreException — dev tool: report failure to user
            $this->error("Failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
