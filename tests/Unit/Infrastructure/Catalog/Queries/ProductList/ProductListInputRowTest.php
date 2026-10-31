<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Catalog\Queries\ProductList;

use App\Infrastructure\Catalog\Queries\ProductList\ProductListInputRow;
use App\Infrastructure\Catalog\Queries\ProductList\ProductListSql;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The fingerprint covers exactly the `list_inputs` columns, so the row class must read exactly those:
 * a property with no column, or a column with no property, lets a response field escape the fingerprint.
 */
#[CoversClass(ProductListInputRow::class)]
final class ProductListInputRowTest extends TestCase
{
    #[Test]
    public function properties_are_exactly_the_list_input_columns(): void
    {
        $properties = \array_map(
            static fn(ReflectionProperty $property): string => $property->getName(),
            new ReflectionClass(ProductListInputRow::class)->getProperties(ReflectionProperty::IS_PUBLIC),
        );
        $columns = \array_map(Str::camel(...), \array_keys(ProductListSql::LIST_INPUT_COLUMNS));

        \sort($properties);
        \sort($columns);

        self::assertSame($columns, $properties);
    }
}
