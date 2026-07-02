<?php

declare(strict_types=1);

namespace App\Application\Linnworks\Enums;

/**
 * Embed depth for a Linnworks purchase order read.
 *
 * Header = single Get_PurchaseOrder call (header only).
 * Core   = single Get_PurchaseOrder call assembled into the full core VO.
 * Full   = core plus notes and extended properties (3 API calls).
 */
enum PurchaseOrderDepth
{
    case Header;
    case Core;
    case Full;
}
