<?php

declare(strict_types=1);

namespace App\Application\Enums;

/**
 * The fixed fixture SKU pool that seeded order lines draw from; each SKU has a matching QBO sandbox Item.
 */
enum FixtureSku: string
{
    case Sku001 = 'FIXTURE-SKU-001';
    case Sku002 = 'FIXTURE-SKU-002';
    case Sku003 = 'FIXTURE-SKU-003';
    case Sku004 = 'FIXTURE-SKU-004';
    case Sku005 = 'FIXTURE-SKU-005';
    case Sku006 = 'FIXTURE-SKU-006';
    case Sku007 = 'FIXTURE-SKU-007';
    case Sku008 = 'FIXTURE-SKU-008';
    case Sku009 = 'FIXTURE-SKU-009';
    case Sku010 = 'FIXTURE-SKU-010';
    case Sku011 = 'FIXTURE-SKU-011';
    case Sku012 = 'FIXTURE-SKU-012';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn(self $sku): string => $sku->value, self::cases());
    }
}
