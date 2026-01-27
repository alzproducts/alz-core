<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use Illuminate\Console\Command;

/**
 * Audit ShopWired product sync by comparing API data with database.
 *
 * Use this command to diagnose sync issues, identify missing products/variations,
 * and verify data integrity between ShopWired API and local database.
 *
 * @example php artisan shopwired:audit-sync
 * @example php artisan shopwired:audit-sync --show-missing
 */
final class ShopwiredAuditSyncCommand extends Command
{
    protected $signature = 'shopwired:audit-sync
                            {--show-missing : Show IDs of missing products/variations}
                            {--limit=20 : Limit number of missing items shown}';

    protected $description = 'Compare ShopWired API product/variation counts with database';

    /**
     * @throws AuthenticationExpiredException
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     */
    public function handle(
        ProductClientInterface $productClient,
        ProductRepositoryInterface $productRepository,
    ): int {
        $this->info('Fetching products from ShopWired API...');
        $apiProducts = $productClient->listAllProducts();

        [$apiProductIds, $apiVariationIds] = $this->extractApiIds($apiProducts);

        $this->info('Counting database records...');
        [$dbProductIds, $dbVariationIds] = $this->getDbIds($productRepository);

        $this->displayComparisonTable($apiProductIds, $apiVariationIds, $dbProductIds, $dbVariationIds);

        $missingProductIds = \array_diff($apiProductIds, $dbProductIds);
        $missingVariationIds = \array_diff($apiVariationIds, $dbVariationIds);

        $this->displayMissingSummary($missingProductIds, $missingVariationIds);
        $this->displayExtraSummary($dbProductIds, $dbVariationIds, $apiProductIds, $apiVariationIds);
        $this->displayMissingDetails($apiProducts, $missingProductIds, $missingVariationIds);

        $hasDiscrepancy = $missingProductIds !== [] || $missingVariationIds !== [];

        return $hasDiscrepancy ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Extract product and variation IDs from API response.
     *
     * @param list<Product> $apiProducts
     *
     * @return array{list<int>, list<int>} [productIds, variationIds]
     */
    private function extractApiIds(array $apiProducts): array
    {
        $productIds = [];
        $variationIds = [];

        foreach ($apiProducts as $product) {
            $productIds[] = $product->id;

            foreach ($product->variations as $variation) {
                $variationIds[] = $variation->id;
            }
        }

        return [$productIds, $variationIds];
    }

    /**
     * Get product and variation IDs from database.
     *
     * @return array{list<int>, list<int>} [productIds, variationIds]
     *
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    private function getDbIds(ProductRepositoryInterface $productRepository): array
    {
        $productIds = $productRepository->getAllExternalIds();
        $variationIds = $productRepository->getAllVariationExternalIds();

        return [$productIds, $variationIds];
    }

    /**
     * Display the comparison table.
     *
     * @param list<int> $apiProductIds
     * @param list<int> $apiVariationIds
     * @param list<int> $dbProductIds
     * @param list<int> $dbVariationIds
     */
    private function displayComparisonTable(
        array $apiProductIds,
        array $apiVariationIds,
        array $dbProductIds,
        array $dbVariationIds,
    ): void {
        $this->newLine();
        $this->table(
            ['Source', 'Products', 'Variations'],
            [
                ['API', \count($apiProductIds), \count($apiVariationIds)],
                ['Database', \count($dbProductIds), \count($dbVariationIds)],
                ['Difference', \count($apiProductIds) - \count($dbProductIds), \count($apiVariationIds) - \count($dbVariationIds)],
            ],
        );
    }

    /**
     * Display summary of missing items.
     *
     * @param array<int> $missingProductIds
     * @param array<int> $missingVariationIds
     */
    private function displayMissingSummary(array $missingProductIds, array $missingVariationIds): void
    {
        $this->newLine();
        $this->info('Missing from database:');
        $this->line('  Products: ' . \count($missingProductIds));
        $this->line('  Variations: ' . \count($missingVariationIds));
    }

    /**
     * Display summary of extra items in database (deleted from API).
     *
     * @param list<int> $dbProductIds
     * @param list<int> $dbVariationIds
     * @param list<int> $apiProductIds
     * @param list<int> $apiVariationIds
     */
    private function displayExtraSummary(
        array $dbProductIds,
        array $dbVariationIds,
        array $apiProductIds,
        array $apiVariationIds,
    ): void {
        $extraProductIds = \array_diff($dbProductIds, $apiProductIds);
        $extraVariationIds = \array_diff($dbVariationIds, $apiVariationIds);

        if ($extraProductIds === [] && $extraVariationIds === []) {
            return;
        }

        $this->newLine();
        $this->warn('Extra in database (deleted from API?):');
        $this->line('  Products: ' . \count($extraProductIds));
        $this->line('  Variations: ' . \count($extraVariationIds));
    }

    /**
     * Display detailed missing items if --show-missing flag is set.
     *
     * @param list<Product> $apiProducts
     * @param array<int> $missingProductIds
     * @param array<int> $missingVariationIds
     */
    private function displayMissingDetails(
        array $apiProducts,
        array $missingProductIds,
        array $missingVariationIds,
    ): void {
        if (!$this->option('show-missing')) {
            return;
        }

        if ($missingProductIds === [] && $missingVariationIds === []) {
            return;
        }

        $limit = (int) $this->option('limit');

        if ($missingProductIds !== []) {
            $this->newLine();
            $this->info('Missing Product IDs (first ' . $limit . '):');
            $this->showMissingWithDetails($apiProducts, \array_slice($missingProductIds, 0, $limit));
        }

        if ($missingVariationIds !== []) {
            $this->newLine();
            $this->info('Missing Variation IDs (first ' . $limit . '):');
            foreach (\array_slice($missingVariationIds, 0, $limit) as $id) {
                $this->line("  - {$id}");
            }
        }
    }

    /**
     * Show missing product IDs with their SKU and title for easier debugging.
     *
     * @param list<Product> $apiProducts
     * @param array<int> $missingIds
     */
    private function showMissingWithDetails(array $apiProducts, array $missingIds): void
    {
        $productMap = [];
        foreach ($apiProducts as $product) {
            $productMap[$product->id] = $product;
        }

        foreach ($missingIds as $id) {
            $product = $productMap[$id] ?? null;
            if ($product !== null) {
                $sku = $product->sku ?? 'N/A';
                $title = \mb_substr($product->title, 0, 50);
                $this->line("  - {$id} | SKU: {$sku} | {$title}");
            } else {
                $this->line("  - {$id}");
            }
        }
    }
}
