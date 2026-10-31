<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\DevTools\Catalog\ProductList\ProductListSnapshotService;
use App\DevTools\Catalog\ProductList\SnapshotComparator;
use App\DevTools\Catalog\ProductList\SnapshotStore;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Local only. Captures the products list bodies and the other products_view readers into tmp/cor-258/<label>/.
 */
final class ProductsListSnapshotCommand extends Command
{
    protected $signature = 'dev:products-list-snapshot
        {--out= : Capture label; files go to tmp/cor-258/<label>/}
        {--compare= : Label of an earlier capture to diff the new capture against}';

    protected $description = 'Capture the products list and its sibling readers for a field-level parity check';

    public function handle(ProductListSnapshotService $snapshot, SnapshotComparator $comparator, SnapshotStore $store): int
    {
        $label = $this->preflightLabel();

        if ($label === null) {
            return self::FAILURE;
        }

        try {
            $names = $snapshot->capture($label);
            $this->info('Captured ' . \count($names) . ' files into ' . $store->directory($label) . ': ' . \implode(', ', $names));

            return $this->compare($comparator, $label);
        } catch (RuntimeException|JsonException|InvalidArgumentException $e) { // @ignoreException - delivery boundary: report any capture failure to the operator
            $this->error($e->getMessage());
            $this->line('  Check: the local database is up and seeded, and the bypass secret and test email are set.');

            return self::FAILURE;
        }
    }

    /**
     * The capture label, or null (with the reason printed) when the capture must not start.
     */
    private function preflightLabel(): ?string
    {
        $label = $this->option('out');

        if (! \is_string($label) || $label === '') {
            $this->error('Pass --out=<label> to name the capture.');

            return null;
        }

        $failure = SeedFakeDataCommand::guardFailure();

        if ($failure !== null) {
            $this->error($failure);
            $this->line('  Check: APP_ENV=local, a loopback DB host and the COR-252 seed (dev:seed-fake).');

            return null;
        }

        return $label;
    }

    /**
     * @throws RuntimeException
     * @throws JsonException
     */
    private function compare(SnapshotComparator $comparator, string $label): int
    {
        $reference = $this->option('compare');

        if (! \is_string($reference) || $reference === '') {
            return self::SUCCESS;
        }

        $differences = $comparator->compare($reference, $label);
        \array_map($this->line(...), $differences);

        if ($differences === []) {
            $this->info("No differences between {$reference} and {$label}.");

            return self::SUCCESS;
        }

        $this->error(\count($differences) . " differences between {$reference} and {$label}.");

        return self::FAILURE;
    }
}
