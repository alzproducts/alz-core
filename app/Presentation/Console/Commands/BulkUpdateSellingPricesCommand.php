<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Shopwired\BulkSellingPriceUpdate\DispatchBulkSellingPriceJobsUseCase;
use App\Application\Shopwired\BulkSellingPriceUpdate\Results\BulkSellingPriceDispatchResult;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Presentation\Console\Parsers\SellingPriceCsvParser;
use Illuminate\Console\Command;

/**
 * Bulk update ShopWired selling prices from a CSV, fanning out one queued job per
 * owning product. Each job mirrors the single-product price update exactly: same
 * ShopWired batch write, price periods, Linnworks EP sync, Slack notification.
 *
 * ⚠️ PRODUCTION ONLY — writes live ShopWired selling prices. Run against the production
 * worker; a local run would use production ShopWired credentials but the local DB,
 * stranding price periods in the wrong database (prior incident with inventory:update-skus).
 *
 *   railway ssh -s alz-core-worker php artisan catalog:bulk-update-selling-prices storage/prices.csv --dry-run
 *   railway ssh -s alz-core-worker php artisan catalog:bulk-update-selling-prices storage/prices.csv
 *
 * CSV format (header row required): sku,price   (price is gross / VAT-inclusive).
 */
final class BulkUpdateSellingPricesCommand extends Command
{
    private const int PREVIEW_ROWS = 10;

    protected $signature = 'catalog:bulk-update-selling-prices
                            {file : Path to CSV file (columns: sku,price)}
                            {--dry-run : Validate CSV shape and prices without resolving SKUs or dispatching}';

    protected $description = 'Bulk update ShopWired selling prices from a CSV via queued jobs';

    /**
     * @throws DatabaseOperationFailedException On SKU map query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function handle(DispatchBulkSellingPriceJobsUseCase $useCase, SellingPriceCsvParser $parser): int
    {
        $file = $this->argument('file');
        if (! \is_file($file) || ! \is_readable($file)) {
            $this->error("CSV file not found or not readable: {$file}");
            return self::FAILURE;
        }

        [$commands, $errors] = $parser->parse($file);
        if ($errors !== []) {
            $this->reportRowErrors($errors);
            return self::FAILURE;
        }

        if ($commands === []) {
            $this->error('CSV contained no data rows.');
            return self::FAILURE;
        }

        return $this->dispatchOrPreview($useCase, $commands);
    }

    /**
     * @param non-empty-list<UpdatePriceCommand> $commands
     *
     * @throws DatabaseOperationFailedException On SKU map query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function dispatchOrPreview(DispatchBulkSellingPriceJobsUseCase $useCase, array $commands): int
    {
        if ($this->option('dry-run')) {
            $this->renderPreview($commands);

            return self::SUCCESS;
        }

        try {
            $result = $useCase->execute($commands);
        } catch (ValidationFailedException $e) {
            $this->reportUnresolvedSkus($e);

            return self::FAILURE;
        }

        $this->renderSummary($result);

        return self::SUCCESS;
    }

    /**
     * @param non-empty-list<UpdatePriceCommand> $commands
     */
    private function renderPreview(array $commands): void
    {
        $rows = [];
        foreach (\array_slice($commands, 0, self::PREVIEW_ROWS) as $command) {
            $rows[] = [$command->sku->value, \number_format($command->price?->toGross() ?? 0.0, 2)];
        }

        $this->info('DRY RUN — no jobs dispatched:');
        $this->newLine();
        $this->table(['SKU', 'Price (inc VAT)'], $rows);
        $this->line(\sprintf('  Totals: %d SKUs (showing first %d)', \count($commands), \count($rows)));
        $this->newLine();
        $this->warn('Dry-run validates CSV shape and VAT round-trip only — it does NOT resolve SKUs to products.');
        $this->warn('Unresolved SKUs are detected on the real run, which fails fast before dispatching any job.');
        $this->warn('Remove --dry-run to dispatch.');
    }

    private function renderSummary(BulkSellingPriceDispatchResult $result): void
    {
        $this->info('✓ Bulk selling price jobs dispatched:');
        $this->table(
            ['Products', 'SKUs', 'Jobs'],
            [[$result->productCount, $result->skuCount, $result->jobsDispatched]],
        );
        $this->line('  Monitor progress: php artisan horizon');
    }

    private function reportUnresolvedSkus(ValidationFailedException $e): void
    {
        $this->error($e->reason . ' — nothing dispatched:');

        /** @var list<string> $unresolved */
        $unresolved = $e->context()['unresolved_skus'] ?? [];
        foreach ($unresolved as $sku) {
            $this->line('  • ' . $sku);
        }
    }

    /**
     * @param list<string> $errors
     */
    private function reportRowErrors(array $errors): void
    {
        $this->error(\sprintf('Found %d invalid row(s) — nothing dispatched:', \count($errors)));
        foreach ($errors as $error) {
            $this->line('  • ' . $error);
        }
    }
}
