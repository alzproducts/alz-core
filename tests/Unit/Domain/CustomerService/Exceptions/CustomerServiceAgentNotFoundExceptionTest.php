<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\Exceptions;

use App\Domain\CustomerService\Exceptions\CustomerServiceAgentNotFoundException;
use App\Domain\Exceptions\DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CustomerServiceAgentNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function it_creates_exception_with_email(): void
    {
        $exception = new CustomerServiceAgentNotFoundException('user@example.com');

        $this->assertSame('user@example.com', $exception->email);
    }

    #[Test]
    public function it_formats_message_with_email(): void
    {
        $exception = new CustomerServiceAgentNotFoundException('agent@company.com');

        $this->assertSame(
            'No customer service account found for email: agent@company.com',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function it_extends_domain_exception(): void
    {
        $exception = new CustomerServiceAgentNotFoundException('test@example.com');

        $this->assertInstanceOf(DomainException::class, $exception);
    }
}
