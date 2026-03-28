<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exceptions;

use App\Domain\Exceptions\Infrastructure\ConfigurationNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests message formatting logic only.
 * Inheritance, previous exception support are standard PHP - verified by PHPStan.
 *
 * Note: No #[CoversClass] attribute because exception classes are excluded from coverage in phpunit.xml.
 */
final class ConfigurationNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function it_formats_message_with_config_name(): void
    {
        $exception = new ConfigurationNotFoundException('dashboard_settings');

        $this->assertSame('Required configuration not found', $exception->getMessage());
        $this->assertSame(['config_name' => 'dashboard_settings'], $exception->context());
    }
}
