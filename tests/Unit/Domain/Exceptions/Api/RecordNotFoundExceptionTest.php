<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions\Api;

use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RecordNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function it_is_a_transient_api_failure(): void
    {
        $exception = new RecordNotFoundException('Product', 42);

        self::assertInstanceOf(TransientApiFailure::class, $exception);
    }

    #[Test]
    public function service_name_is_always_database(): void
    {
        $exception = new RecordNotFoundException('ProductVariation', 'SKU-1');

        self::assertSame('Database', $exception->serviceName);
    }

    #[Test]
    public function message_is_static(): void
    {
        $exception = new RecordNotFoundException('Brand', 7);

        self::assertSame('Record not found in database', $exception->getMessage());
    }

    #[Test]
    public function retry_after_defaults_to_null(): void
    {
        $exception = new RecordNotFoundException('Category', 99);

        self::assertNull($exception->retryAfter);
    }

    #[Test]
    public function retry_after_is_exposed_when_provided(): void
    {
        $exception = new RecordNotFoundException('Order', 555, retryAfter: 30);

        self::assertSame(30, $exception->retryAfter);
    }

    #[Test]
    public function context_exposes_resource_type_and_id(): void
    {
        $exception = new RecordNotFoundException('ProductVariation', 'SKU-ABC-123');

        self::assertSame([
            'service_name' => 'Database',
            'resource_type' => 'ProductVariation',
            'resource_id' => 'SKU-ABC-123',
        ], $exception->context());
    }

    #[Test]
    public function context_omits_null_retry_after_but_includes_numeric_retry_after(): void
    {
        $withRetry = new RecordNotFoundException('Order', 42, retryAfter: 5);

        self::assertSame(5, $withRetry->context()['retry_after']);
    }

    #[Test]
    public function it_chains_previous_exception(): void
    {
        $previous = new RuntimeException('db conn lost');
        $exception = new RecordNotFoundException('Customer', 1, previous: $previous);

        self::assertSame($previous, $exception->getPrevious());
    }
}
