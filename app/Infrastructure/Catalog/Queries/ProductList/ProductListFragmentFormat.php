<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use App\Domain\Catalog\Product\Enums\ProductInclude;

/**
 * Identity of a stored products-list fragment: its format version, shape and fingerprint salt.
 */
final class ProductListFragmentFormat
{
    /** Bump when a fragment's body changes for the same inputs; the release id already covers deploys. */
    public const int VERSION = 1;

    /**
     * The canonical include set, e.g. 'stock' or 'inventory,stock'; 'none' when there are no includes.
     *
     * @param list<ProductInclude> $includes
     */
    public static function shape(array $includes): string
    {
        $values = \array_values(\array_unique(\array_map(
            static fn(ProductInclude $include): string => $include->value,
            $includes,
        )));
        \sort($values);

        return $values === [] ? 'none' : \implode(',', $values);
    }

    public static function salt(string $release, string $shape): string
    {
        return self::VERSION . ':' . $release . ':' . $shape;
    }
}
