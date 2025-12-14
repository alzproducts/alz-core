<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CustomerService\ValueObjects;

use App\Domain\CustomerService\ValueObjects\SupportAgent;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(SupportAgent::class)]
final class SupportAgentTest extends TestCase
{
    #[Test]
    public function it_creates_valid_support_agent(): void
    {
        $agent = new SupportAgent(
            id: 12345,
            email: 'agent@example.com',
            firstName: 'John',
            lastName: 'Doe',
        );

        $this->assertSame(12345, $agent->id);
        $this->assertSame('agent@example.com', $agent->email);
        $this->assertSame('John', $agent->firstName);
        $this->assertSame('Doe', $agent->lastName);
    }

    #[Test]
    public function it_accepts_agent_id_of_one(): void
    {
        $agent = new SupportAgent(
            id: 1,
            email: 'agent@example.com',
            firstName: 'Test',
            lastName: 'Agent',
        );

        $this->assertSame(1, $agent->id);
    }

    #[Test]
    public function it_rejects_zero_agent_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent ID must be positive');

        new SupportAgent(
            id: 0,
            email: 'agent@example.com',
            firstName: 'Test',
            lastName: 'Agent',
        );
    }

    #[Test]
    public function it_rejects_negative_agent_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent ID must be positive');

        new SupportAgent(
            id: -1,
            email: 'agent@example.com',
            firstName: 'Test',
            lastName: 'Agent',
        );
    }

    #[Test]
    public function it_rejects_empty_agent_email(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent email cannot be empty');

        new SupportAgent(
            id: 100,
            email: '',
            firstName: 'Test',
            lastName: 'Agent',
        );
    }

    #[Test]
    public function it_creates_agent_with_role(): void
    {
        $agent = new SupportAgent(
            id: 12345,
            email: 'agent@example.com',
            firstName: 'John',
            lastName: 'Doe',
            role: 'admin',
        );

        $this->assertSame('admin', $agent->role);
    }

    #[Test]
    public function it_creates_agent_without_role(): void
    {
        $agent = new SupportAgent(
            id: 12345,
            email: 'agent@example.com',
            firstName: 'John',
            lastName: 'Doe',
        );

        $this->assertNull($agent->role);
    }

    #[Test]
    public function it_accepts_null_role_explicitly(): void
    {
        $agent = new SupportAgent(
            id: 12345,
            email: 'agent@example.com',
            firstName: 'John',
            lastName: 'Doe',
            role: null,
        );

        $this->assertNull($agent->role);
    }
}
