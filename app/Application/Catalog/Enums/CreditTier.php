<?php

declare(strict_types=1);

namespace App\Application\Catalog\Enums;

/**
 * IMPORTANT: Backed values are duplicated as string literals in the
 * catalog.credit_product_popularity_ranking SQL view — update both together.
 *
 * See: 2026_05_22_100001_create_catalog_credit_product_popularity_ranking_view.php
 */
enum CreditTier: string
{
    case Tier1 = 'Credit - Tier 1';
    case Tier2 = 'Credit - Tier 2';
    case Tier3 = 'Credit - Tier 3';
}
