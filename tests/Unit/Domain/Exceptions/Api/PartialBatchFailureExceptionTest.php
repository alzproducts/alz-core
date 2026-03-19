<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions\Api;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\PartialBatchFailureException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PartialBatchFailureExceptionTest extends TestCase
{
    #[Test]
    public function message_includes_service_name_and_failure_count(): void
    {
        $failures = [
            new ExternalServiceUnavailableException('Shopwired'),
            new InvalidApiResponseException('Shopwired'),
        ];

        $exception = new PartialBatchFailureException(
            failures: $failures,
            serviceName: 'Shopwired',
        );

        self::assertSame('Shopwired: 2 batch chunk(s) failed', $exception->getMessage());
        self::assertSame('Shopwired', $exception->serviceName);
        self::assertCount(2, $exception->failures);
    }

    #[Test]
    public function single_failure(): void
    {
        $failure = new ExternalServiceUnavailableException('Shopwired');

        $exception = new PartialBatchFailureException(
            failures: [$failure],
            serviceName: 'Shopwired',
        );

        self::assertSame('Shopwired: 1 batch chunk(s) failed', $exception->getMessage());
        self::assertSame($failure, $exception->failures[0]);
    }

    #[Test]
    public function failures_array_is_accessible(): void
    {
        $transient = new ExternalServiceUnavailableException('Shopwired', 60);
        $permanent = new InvalidApiResponseException('Shopwired');

        $exception = new PartialBatchFailureException(
            failures: [$transient, $permanent],
            serviceName: 'Shopwired',
        );

        self::assertInstanceOf(ExternalServiceUnavailableException::class, $exception->failures[0]);
        self::assertInstanceOf(InvalidApiResponseException::class, $exception->failures[1]);
    }
}
