<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\Exceptions;

use App\Domain\CustomerService\Exceptions\CustomerServiceAgentNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests message formatting logic only.
 * Inheritance and standard PHP behavior are verified by PHPStan.
 */
final class CustomerServiceAgentNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function it_formats_message_with_email(): void
    {
        $exception = new CustomerServiceAgentNotFoundException('agent@company.com');

        $this->assertSame('Customer service agent not found', $exception->getMessage());
        $this->assertSame(['email' => 'agent@company.com'], $exception->context());
    }
}
