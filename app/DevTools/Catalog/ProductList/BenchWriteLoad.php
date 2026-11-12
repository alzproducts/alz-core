<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use RuntimeException;

/**
 * The background write load at its stated rates. Every writer touches only the local database through repository
 * write methods, never a ShopWired or Linnworks client, and restore() puts the seed back as it was.
 */
final readonly class BenchWriteLoad
{
    public function __construct(
        private CatalogueWriter $catalogue,
        private StockMirrorWriter $stockMirror,
        private CostPriceWriter $costPrices,
        private SupplierSyncWriter $supplierSync,
        private PopularitySnapshotWriter $popularity,
        private CatalogueRowsSnapshot $catalogueRows,
    ) {}

    /**
     * @return list<string> Notes on writers that will not run
     *
     * @throws RuntimeException
     */
    public function captureBaseline(): array
    {
        $this->catalogue->captureBaseline();
        $this->catalogueRows->capture();
        $this->stockMirror->captureBaseline();
        $this->costPrices->captureBaseline();
        $this->supplierSync->captureBaseline();

        return $this->popularity->captureBaseline()
            ? []
            : ['popularity_snapshot skipped: snapshots dated today already exist, and a second write today would collide.'];
    }

    /**
     * @param float $onceDelaySeconds When the once-per-run writers fire, counted from the start of the load
     *
     * @return list<BenchWriter>
     */
    public static function writers(float $onceDelaySeconds): array
    {
        return [
            new BenchWriter(BenchWriteKind::ProductWebhookUpsert, 2.0, 1.0),
            new BenchWriter(BenchWriteKind::StockMirror, 30.0, 5.0),
            new BenchWriter(BenchWriteKind::CostPriceUpdate, 60.0, 10.0),
            new BenchWriter(BenchWriteKind::SupplierSync, 60.0, 40.0),
            new BenchWriter(BenchWriteKind::CategoryWebhook, 300.0, 15.0),
            new BenchWriter(BenchWriteKind::DailySyncVariationReplace, null, $onceDelaySeconds),
            new BenchWriter(BenchWriteKind::PopularitySnapshot, null, $onceDelaySeconds + 30.0),
        ];
    }

    /**
     * @throws RuntimeException
     */
    public function perform(BenchWriteKind $kind): void
    {
        match ($kind) {
            BenchWriteKind::ProductWebhookUpsert => $this->catalogue->webhookUpsert(),
            BenchWriteKind::StockMirror => $this->stockMirror->mirror(),
            BenchWriteKind::CostPriceUpdate => $this->costPrices->update(),
            BenchWriteKind::SupplierSync => $this->supplierSync->sync(),
            BenchWriteKind::CategoryWebhook => $this->catalogue->categoryWebhook(),
            BenchWriteKind::DailySyncVariationReplace => $this->catalogue->replaceVariations($this->catalogue->loadCatalogue()),
            BenchWriteKind::PopularitySnapshot => $this->popularity->write(),
        };
    }

    /**
     * @throws RuntimeException
     */
    public function restore(): void
    {
        $this->stockMirror->restore();
        $this->costPrices->restore();
        $this->popularity->restore();
        $this->catalogueRows->restore();
    }
}
