<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Inventory\UseCases\ProcessSkuUpdatesUseCase;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Inventory\Commands\UpdateSkuCommand;
use App\Domain\Inventory\Enums\SkuUpdateReason;
use Illuminate\Console\Command;
use TypeError;
use ValueError;

/**
 * Update SKUs across Linnworks and ShopWired.
 *
 * Dispatches jobs to synchronously update SKUs in both systems.
 * Jobs are serialized via ShouldBeUnique to prevent race conditions.
 *
 * ⚠️ PRODUCTION ONLY: This command modifies LIVE Linnworks and ShopWired data.
 * The audit trail (operations.sku_changes) is critical for tracking changes
 * used in historic sales reports. Running locally writes audit records to your
 * local database while making REAL changes to production systems - leaving no
 * traceable record in production. Always run via `railway ssh` in production.
 *
 * Examples:
 *   railway ssh -s alz-core 'php artisan inventory:update-skus ABC123:XYZ789 --reason=fix_sku_mismatch'
 *   railway ssh -s alz-core 'php artisan inventory:update-skus OLD-SKU:generate --reason=shorten_long_sku'
 */
final class UpdateSkusCommand extends Command
{
    protected $signature = 'inventory:update-skus
                            {mappings* : SKU mappings in old_sku:new_sku or old_sku:generate format}
                            {--reason=other : Reason for change (shorten_long_sku, fix_sku_mismatch, standardize_format, merge_products, other)}
                            {--dry-run : Show what would be dispatched without dispatching}';

    protected $description = 'Update SKUs across Linnworks and ShopWired';

    /**
     * @throws TypeError When array_column fails (defensive - shouldn't happen with enum)
     */
    public function handle(ProcessSkuUpdatesUseCase $dispatchUseCase): int
    {
        /** @var list<string> $mappings */
        $mappings = $this->argument('mappings');
        $reasonString = (string) $this->option('reason');
        $dryRun = $this->option('dry-run');

        if ($mappings === []) {
            $this->error('At least one SKU mapping is required');
            $this->line('Format: old_sku:new_sku or old_sku:generate');

            return self::FAILURE;
        }

        $reason = $this->parseReason($reasonString);

        if ($reason === null) {
            return self::FAILURE;
        }

        [$commands, $errors] = $this->parseMappings($mappings, $reason);

        if ($errors !== []) {
            $this->displayErrors($errors);

            return self::FAILURE;
        }

        $this->displaySummary($commands, $dryRun);

        if ($dryRun) {
            $this->newLine();
            $this->warn('No jobs dispatched (dry run). Remove --dry-run to execute.');

            return self::SUCCESS;
        }

        $dispatched = $dispatchUseCase->execute($commands);

        $this->newLine();
        $this->info('✓ ' . $dispatched . ' job(s) dispatched.');
        $this->line('  Jobs are serialized - they will execute one at a time.');
        $this->line('  Monitor progress: php artisan horizon');

        return self::SUCCESS;
    }

    /**
     * @throws TypeError When array_column fails (defensive - shouldn't happen with enum)
     */
    private function parseReason(string $reasonString): ?SkuUpdateReason
    {
        try {
            return SkuUpdateReason::from($reasonString);
        } catch (ValueError) {
            $this->error("Invalid reason: {$reasonString}");
            $this->line('Valid reasons: ' . \implode(', ', \array_column(SkuUpdateReason::cases(), 'value')));

            return null;
        }
    }

    /**
     * @param list<string> $mappings
     *
     * @return array{list<UpdateSkuCommand>, list<string>}
     */
    private function parseMappings(array $mappings, SkuUpdateReason $reason): array
    {
        $commands = [];
        $errors = [];

        foreach ($mappings as $mapping) {
            $parsed = $this->parseMapping($mapping, $reason);

            if ($parsed instanceof UpdateSkuCommand) {
                $commands[] = $parsed;
            } else {
                $errors[] = $parsed;
            }
        }

        return [$commands, $errors];
    }

    /**
     * @param list<string> $errors
     */
    private function displayErrors(array $errors): void
    {
        $this->error('Invalid mappings:');

        foreach ($errors as $error) {
            $this->line("  - {$error}");
        }
    }

    /**
     * @param list<UpdateSkuCommand> $commands
     */
    private function displaySummary(array $commands, bool $dryRun): void
    {
        $this->info($dryRun ? 'DRY RUN - Would dispatch:' : 'Dispatching jobs...');
        $this->newLine();

        $this->table(
            ['Old SKU', 'New SKU', 'Type'],
            \array_map(
                static fn(UpdateSkuCommand $cmd): array => [
                    $cmd->oldSku,
                    $cmd->newSku !== null ? $cmd->newSku->value : '(auto-generate)',
                    $cmd->type->value,
                ],
                $commands,
            ),
        );
    }

    /**
     * Parse a mapping string into an UpdateSkuCommand.
     *
     * @return UpdateSkuCommand|string Command on success, error message on failure
     */
    private function parseMapping(string $mapping, SkuUpdateReason $reason): UpdateSkuCommand|string
    {
        $parts = \explode(':', $mapping, 2);

        if (\count($parts) !== 2) {
            return "'{$mapping}' - must be in old_sku:new_sku or old_sku:generate format";
        }

        [$oldSku, $newSkuOrGenerate] = $parts;

        if (\mb_trim($oldSku) === '') {
            return "'{$mapping}' - old SKU cannot be empty";
        }

        // Handle auto-generate
        if (\mb_strtolower($newSkuOrGenerate) === 'generate') {
            return UpdateSkuCommand::generated($oldSku, $reason);
        }

        // Handle provided SKU
        try {
            $newSku = Sku::fromString($newSkuOrGenerate);

            return UpdateSkuCommand::provided($oldSku, $newSku, $reason);
        } catch (InvalidSkuException $e) {
            $ctx = $e->context();
            $detail = $ctx !== [] ? ' — ' . \json_encode($ctx) : '';

            return "'{$mapping}' - invalid new SKU: {$e->getMessage()}{$detail}";
        }
    }
}
