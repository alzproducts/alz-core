<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions\Inventory;

use App\Domain\Exceptions\Inventory\SkuGenerationFailedException;
use App\Domain\Exceptions\Inventory\SkuUpdateFailedException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkuGenerationFailedException::class)]
#[CoversClass(SkuUpdateFailedException::class)]
final class SkuExceptionsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | SkuGenerationFailedException Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function sku_generation_failed_stores_reason(): void
    {
        $exception = new SkuGenerationFailedException('API timeout');

        self::assertSame('API timeout', $exception->reason);
        self::assertStringContainsString('Failed to generate new SKU', $exception->getMessage());
        self::assertStringContainsString('API timeout', $exception->getMessage());
    }

    #[Test]
    public function sku_generation_failed_stores_previous_exception(): void
    {
        $previous = new Exception('Original error');
        $exception = new SkuGenerationFailedException('Service unavailable', $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    /*
    |--------------------------------------------------------------------------
    | SkuUpdateFailedException Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function sku_update_failed_stores_context(): void
    {
        $exception = new SkuUpdateFailedException(
            oldSku: 'OLD-123',
            newSku: 'NEW-456',
            failedSystem: 'shopwired',
            reason: 'Product not found',
        );

        self::assertSame('OLD-123', $exception->oldSku);
        self::assertSame('NEW-456', $exception->newSku);
        self::assertSame('shopwired', $exception->failedSystem);
        self::assertSame('Product not found', $exception->reason);
    }

    #[Test]
    public function sku_update_failed_formats_message(): void
    {
        $exception = new SkuUpdateFailedException(
            oldSku: 'OLD',
            newSku: 'NEW',
            failedSystem: 'linnworks',
            reason: 'Connection timeout',
        );

        self::assertStringContainsString('linnworks', $exception->getMessage());
        self::assertStringContainsString('Connection timeout', $exception->getMessage());
    }

    #[Test]
    public function sku_update_failed_stores_previous_exception(): void
    {
        $previous = new Exception('Root cause');
        $exception = new SkuUpdateFailedException(
            oldSku: 'A',
            newSku: 'B',
            failedSystem: 'test',
            reason: 'Failed',
            previous: $previous,
        );

        self::assertSame($previous, $exception->getPrevious());
    }
}
