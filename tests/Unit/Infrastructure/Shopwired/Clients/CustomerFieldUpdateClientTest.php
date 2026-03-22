<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Clients;

use App\Domain\Customer\ValueObjects\CustomerFieldUpdate;
use App\Infrastructure\Shopwired\Clients\CustomerFieldUpdateClient;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CustomerFieldUpdateClient::class)]
final class CustomerFieldUpdateClientTest extends TestCase
{
    private ShopwiredTransportInterface&MockInterface $transport;

    private CustomerFieldUpdateClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(ShopwiredTransportInterface::class);
        $this->client = new CustomerFieldUpdateClient($this->transport);
    }

    #[Test]
    public function it_builds_correct_payload_for_first_name(): void
    {
        $this->transport->shouldReceive('put')
            ->once()
            ->with('customers/99', ['firstName' => 'John']);

        $this->client->update(99, CustomerFieldUpdate::firstName('John'));
    }

    #[Test]
    public function it_does_not_call_transport_on_empty_updates(): void
    {
        $this->transport->shouldNotReceive('put');

        $this->client->update(99);
    }
}
