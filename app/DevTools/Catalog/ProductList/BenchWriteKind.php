<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

enum BenchWriteKind: string
{
    case ProductWebhookUpsert = 'product_webhook_upsert';
    case StockMirror = 'stock_mirror';
    case CostPriceUpdate = 'cost_price_update';
    case SupplierSync = 'supplier_sync';
    case CategoryWebhook = 'category_webhook';
    case DailySyncVariationReplace = 'daily_sync_variation_replace';
    case PopularitySnapshot = 'popularity_snapshot';
}
