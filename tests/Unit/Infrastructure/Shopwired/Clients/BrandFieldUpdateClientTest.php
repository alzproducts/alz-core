<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Clients;

use App\Domain\Catalog\Brand\ValueObjects\BrandFieldUpdate;
use App\Infrastructure\Shopwired\Clients\BrandFieldUpdateClient;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(BrandFieldUpdateClient::class)]
final class BrandFieldUpdateClientTest extends TestCase
{
    private ShopwiredTransportInterface&MockInterface $transport;

    private BrandFieldUpdateClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(ShopwiredTransportInterface::class);
        $this->client = new BrandFieldUpdateClient($this->transport);
    }

    #[Test]
    public function it_builds_correct_payload_for_title(): void
    {
        $this->transport->shouldReceive('put')
            ->once()
            ->with('brands/7', ['title' => 'Acme']);

        $this->client->update(7, BrandFieldUpdate::title('Acme'));
    }

    #[Test]
    public function it_maps_all_fields_correctly(): void
    {
        $this->transport->shouldReceive('put')
            ->once()
            ->with('brands/7', [
                'title' => 'Acme',
                'description' => 'A great brand',
                'metaTitle' => 'SEO Title',
                'metaDescription' => 'SEO Description',
            ]);

        $this->client->update(
            7,
            BrandFieldUpdate::title('Acme'),
            BrandFieldUpdate::description('A great brand'),
            BrandFieldUpdate::metaTitle('SEO Title'),
            BrandFieldUpdate::metaDescription('SEO Description'),
        );
    }

    #[Test]
    public function it_does_not_call_transport_on_empty_updates(): void
    {
        $this->transport->shouldNotReceive('put');

        $this->client->update(7);
    }
}
