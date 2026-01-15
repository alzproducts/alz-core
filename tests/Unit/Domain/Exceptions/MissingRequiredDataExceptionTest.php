<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions;

use App\Domain\Exceptions\MissingRequiredDataException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests message formatting logic only.
 * Inheritance, previous exception support are standard PHP - verified by PHPStan.
 *
 * Note: No #[CoversClass] attribute because exception classes are excluded from coverage in phpunit.xml.
 */
final class MissingRequiredDataExceptionTest extends TestCase
{
    #[Test]
    public function it_formats_message_with_data_type_and_operation(): void
    {
        $exception = new MissingRequiredDataException(
            dataType: 'customer trade status',
            operation: 'Mixpanel order sync',
        );

        $this->assertSame(
            'Required customer trade status data not available for Mixpanel order sync',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function it_appends_resolution_when_provided(): void
    {
        $exception = new MissingRequiredDataException(
            dataType: 'customer trade status',
            operation: 'Mixpanel order sync',
            resolution: 'Ensure customer sync job has run before order sync',
        );

        $this->assertSame(
            'Required customer trade status data not available for Mixpanel order sync. Ensure customer sync job has run before order sync',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function it_exposes_data_type_property(): void
    {
        $exception = new MissingRequiredDataException(
            dataType: 'product inventory',
            operation: 'stock update',
        );

        $this->assertSame('product inventory', $exception->dataType);
    }

    #[Test]
    public function it_exposes_operation_property(): void
    {
        $exception = new MissingRequiredDataException(
            dataType: 'product inventory',
            operation: 'stock update',
        );

        $this->assertSame('stock update', $exception->operation);
    }

    #[Test]
    public function it_exposes_resolution_property_when_set(): void
    {
        $exception = new MissingRequiredDataException(
            dataType: 'product inventory',
            operation: 'stock update',
            resolution: 'Run inventory sync first',
        );

        $this->assertSame('Run inventory sync first', $exception->resolution);
    }

    #[Test]
    public function it_exposes_null_resolution_when_not_set(): void
    {
        $exception = new MissingRequiredDataException(
            dataType: 'product inventory',
            operation: 'stock update',
        );

        $this->assertNull($exception->resolution);
    }

    #[Test]
    public function it_preserves_previous_exception(): void
    {
        $previous = new RuntimeException('Original error');

        $exception = new MissingRequiredDataException(
            dataType: 'customer data',
            operation: 'order sync',
            previous: $previous,
        );

        $this->assertSame($previous, $exception->getPrevious());
    }
}
