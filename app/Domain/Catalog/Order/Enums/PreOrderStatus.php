<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\Enums;

/**
 * Pre-order status derived from product-level isPreorder flags.
 *
 * Indicates whether an order contains pre-order items:
 * - None: No products have isPreorder=true
 * - Partial: Some (but not all) products have isPreorder=true
 * - Full: ALL products have isPreorder=true
 */
enum PreOrderStatus: string
{
    case None = 'none';
    case Partial = 'partial';
    case Full = 'full';
}
