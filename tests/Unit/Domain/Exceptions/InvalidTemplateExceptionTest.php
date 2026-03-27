<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions;

use App\Domain\Exceptions\Inventory\InvalidTemplateException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests message formatting logic only.
 *
 * Note: No #[CoversClass] attribute because exception classes are excluded from coverage in phpunit.xml.
 */
final class InvalidTemplateExceptionTest extends TestCase
{
    #[Test]
    public function it_creates_with_sku_and_reason(): void
    {
        $exception = new InvalidTemplateException('TEMPLATE-SKU', 'missing category');

        self::assertSame('TEMPLATE-SKU', $exception->templateSku);
        self::assertSame('missing category', $exception->reason);
        self::assertSame('Invalid template stock item', $exception->getMessage());
        self::assertSame(['template_sku' => 'TEMPLATE-SKU', 'reason' => 'missing category'], $exception->context());
    }

    #[Test]
    public function no_default_supplier_factory_creates_correct_message(): void
    {
        $exception = InvalidTemplateException::noDefaultSupplier('MY-TEMPLATE');

        self::assertSame('MY-TEMPLATE', $exception->templateSku);
        self::assertSame('Invalid template stock item', $exception->getMessage());
        self::assertSame('no default supplier configured', $exception->context()['reason']);
    }
}
