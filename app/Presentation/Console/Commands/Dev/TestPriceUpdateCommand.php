<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductSellingPricesUseCase;
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
 * Calls UpdateProductSellingPricesUseCase directly, triggering all downstream
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
    public function handle(UpdateProductSellingPricesUseCase $useCase): int
    {
        $salePrice = (float) $this->argument('sale-price');
        if ($salePrice <= 0) {
            $this->error('Sale price must be greater than 0.');
            return self::FAILURE;
        }

        try {
            $saleSettings = $this->buildSaleSettings();
        } catch (DateMalformedStringException) {
            $this->error('Invalid date format: ' . ($this->option('end-date') ?? '(null)') . ' (expected Y-m-d)');
            return self::FAILURE;
        }

        /** @var string $sku */
        $sku = \config('shopwired.test_product.sku');
        $this->info("Updating SKU {$sku} → sale price £{$salePrice}");

        return $this->executePriceUpdate($useCase, Sku::fromTrusted($sku), $salePrice, $saleSettings);
    }

    /**
     * @throws DateMalformedStringException
     */
    private function buildSaleSettings(): SaleSettings
    {
        /** @var string $reason */
        $reason = $this->option('reason');
        /** @var string|null $endDateStr */
        $endDateStr = $this->option('end-date');

        return new SaleSettings(
            saleReason: $reason,
            saleStartDate: new DateTimeImmutable(),
            saleEndDate: $endDateStr !== null ? new DateTimeImmutable($endDateStr) : null,
        );
    }

    private function executePriceUpdate(UpdateProductSellingPricesUseCase $useCase, Sku $sku, float $salePrice, SaleSettings $saleSettings): int
    {
        try {
            $result = $useCase->execute(
                skuUpdates: [new UpdatePriceCommand(sku: $sku, salePrice: Money::inclusive($salePrice))],
                saleSettings: $saleSettings,
            );

            return $this->displayPriceUpdateResult($result);
        } catch (Throwable $e) { // @ignoreException — dev tool: report failure to user
            $this->error("Failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function displayPriceUpdateResult(PriceUpdateResult $result): int
    {
        $this->info("Done — {$result->succeeded} succeeded, " . \count($result->skipped) . ' skipped');

        foreach ($result->skipped as $skip) {
            $this->warn("  Skipped {$skip->sku->value}: {$skip->reason}");
        }

        foreach ($result->permanentFailures as $failure) {
            $this->error("  Failed: {$failure->error}");
        }

        return $result->permanentFailures !== [] ? self::FAILURE : self::SUCCESS;
    }
}
