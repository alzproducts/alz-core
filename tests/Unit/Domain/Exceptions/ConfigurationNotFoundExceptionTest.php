<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions;

use App\Domain\Exceptions\ConfigurationNotFoundException;
use App\Domain\Exceptions\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(ConfigurationNotFoundException::class)]
final class ConfigurationNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function it_creates_exception_with_config_name(): void
    {
        $exception = new ConfigurationNotFoundException('escalations');

        $this->assertSame('escalations', $exception->configName);
    }

    #[Test]
    public function it_formats_message_with_config_name(): void
    {
        $exception = new ConfigurationNotFoundException('dashboard_settings');

        $this->assertSame(
            "Required configuration 'dashboard_settings' not found or disabled",
            $exception->getMessage(),
        );
    }

    #[Test]
    public function it_extends_domain_exception(): void
    {
        $exception = new ConfigurationNotFoundException('test_config');

        $this->assertInstanceOf(DomainException::class, $exception);
    }

    #[Test]
    public function it_supports_previous_exception(): void
    {
        $previous = new RuntimeException('Database error');
        $exception = new ConfigurationNotFoundException('api_keys', $previous);

        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame('Database error', $exception->getPrevious()?->getMessage());
    }

    #[Test]
    public function it_allows_null_previous_exception(): void
    {
        $exception = new ConfigurationNotFoundException('settings');

        $this->assertNull($exception->getPrevious());
    }
}
