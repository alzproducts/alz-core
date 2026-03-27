<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Exceptions;

use App\Domain\Catalog\Product\Exceptions\ProductIdentifierResolutionException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests named constructor formatting logic.
 * No #[CoversClass] - exception classes excluded from coverage.
 */
final class ProductIdentifierResolutionExceptionTest extends TestCase
{
    #[Test]
    public function sku_not_found_formats_message_correctly(): void
    {
        $exception = ProductIdentifierResolutionException::skuNotFound('ABC-123');

        $this->assertSame('ABC-123', $exception->identifier);
        $this->assertSame('sku', $exception->identifierType);
        $this->assertSame('Product identifier could not be resolved', $exception->getMessage());
        $this->assertSame(['identifier' => 'ABC-123', 'identifier_type' => 'sku'], $exception->context());
    }

    #[Test]
    public function product_id_not_found_formats_message_correctly(): void
    {
        $exception = ProductIdentifierResolutionException::productIdNotFound(12345);

        $this->assertSame(12345, $exception->identifier);
        $this->assertSame('product_id', $exception->identifierType);
        $this->assertSame('Product identifier could not be resolved', $exception->getMessage());
        $this->assertSame(['identifier' => 12345, 'identifier_type' => 'product_id'], $exception->context());
    }
}
