<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Contracts;

/**
 * Marker interface for backed enums that represent ShopWired product filter values.
 *
 * Purpose: type-safety seam for the shared DTO / dispatcher / worker job, which all
 * accept `list<ShopwiredFilterValueInterface&\BackedEnum>`. PHPStan uses the
 * intersection to allow `->value` access without referencing any specific filter enum.
 */
interface ShopwiredFilterValueInterface {}
