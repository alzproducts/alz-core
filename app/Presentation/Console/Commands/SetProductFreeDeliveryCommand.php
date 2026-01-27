<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Presentation\Concerns\DispatchesChunkedJobsTrait;
use App\Presentation\Jobs\Shopwired\SetProductFreeDeliveryJob;
use Illuminate\Console\Command;
use ValueError;

/**
 * Set free delivery type on ShopWired products.
 *
 * Dispatches jobs to update the free_delivery custom field in ShopWired.
 * Variation SKUs are resolved to their parent product.
 *
 * Examples:
 *   php artisan shopwired:set-free-delivery SKU123 --type=Standard
 *   php artisan shopwired:set-free-delivery 5585518 --type=Express
 *   php artisan shopwired:set-free-delivery SKU123 SKU456 --type=None
 *   php artisan shopwired:set-free-delivery SKU123 --type=Standard --dry-run
 */
final class SetProductFreeDeliveryCommand extends Command
{
    use DispatchesChunkedJobsTrait;

    protected $signature = 'shopwired:set-free-delivery
                            {identifiers* : Product SKUs or IDs to update}
                            {--type=Standard : Free delivery type (Standard, Express, None)}
                            {--dry-run : Show what would be dispatched without dispatching}';

    protected $description = 'Set free delivery type on ShopWired products';

    public function handle(): int
    {
        /** @var list<string> $identifiers */
        $identifiers = $this->argument('identifiers');
        $typeString = (string) $this->option('type');
        $dryRun = $this->option('dry-run');

        if ($identifiers === []) {
            $this->error('At least one identifier is required');

            return self::FAILURE;
        }

        try {
            $type = FreeDeliveryType::fromString($typeString);
        } catch (ValueError) {
            $this->error("Invalid type: {$typeString}");
            $this->line('Valid types: ' . \implode(', ', \array_column(FreeDeliveryType::cases(), 'value')));

            return self::FAILURE;
        }

        $commands = \array_map(
            static fn(string $id): SetFreeDeliveryCommand => self::parseIdentifier($id, $type),
            $identifiers,
        );

        $this->info($dryRun ? 'DRY RUN - Would dispatch:' : 'Dispatching jobs...');
        $this->newLine();

        $this->table(
            ['Items', 'Type'],
            [[\count($commands), $type->value]],
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('No jobs dispatched (dry run). Remove --dry-run to execute.');

            return self::SUCCESS;
        }

        $jobCount = $this->dispatchInChunks($commands, SetProductFreeDeliveryJob::class);

        $this->newLine();
        $this->info("✓ {$jobCount} job(s) dispatched for " . \count($commands) . ' product(s).');
        $this->line('  Monitor progress: php artisan horizon');

        return self::SUCCESS;
    }

    /**
     * Parse identifier string to typed command.
     *
     * Numeric strings are converted to int (product ID), others remain string (SKU).
     */
    private static function parseIdentifier(string $identifier, FreeDeliveryType $type): SetFreeDeliveryCommand
    {
        $parsedIdentifier = \ctype_digit($identifier) ? (int) $identifier : $identifier;

        return new SetFreeDeliveryCommand($parsedIdentifier, $type);
    }
}
