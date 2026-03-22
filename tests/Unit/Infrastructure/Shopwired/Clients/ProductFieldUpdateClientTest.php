<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Clients;

use App\Domain\Catalog\Product\ValueObjects\ProductFieldUpdate;
use App\Infrastructure\Shopwired\Clients\ProductFieldUpdateClient;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ProductFieldUpdateClient::class)]
final class ProductFieldUpdateClientTest extends TestCase
{
    private ShopwiredTransportInterface&MockInterface $transport;

    private ProductFieldUpdateClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(ShopwiredTransportInterface::class);
        $this->client = new ProductFieldUpdateClient($this->transport);
    }

    #[Test]
    public function it_builds_correct_payload_from_single_update(): void
    {
        $this->transport->shouldReceive('put')
            ->once()
            ->with('products/42', ['title' => 'New Title']);

        $this->client->update(42, ProductFieldUpdate::title('New Title'));
    }

    #[Test]
    public function it_builds_correct_payload_from_multiple_updates(): void
    {
        $this->transport->shouldReceive('put')
            ->once()
            ->with('products/42', [
                'title' => 'New Title',
                'metaTitle' => 'SEO Title',
            ]);

        $this->client->update(
            42,
            ProductFieldUpdate::title('New Title'),
            ProductFieldUpdate::metaTitle('SEO Title'),
        );
    }

    #[Test]
    public function it_maps_all_fields_correctly(): void
    {
        $this->transport->shouldReceive('put')
            ->once()
            ->with('products/1', [
                'title' => 'Title',
                'description' => 'Desc',
                'metaTitle' => 'Meta',
                'metaDescription' => 'MetaDesc',
                'categories' => [10, 20],
            ]);

        $this->client->update(
            1,
            ProductFieldUpdate::title('Title'),
            ProductFieldUpdate::description('Desc'),
            ProductFieldUpdate::metaTitle('Meta'),
            ProductFieldUpdate::metaDescription('MetaDesc'),
            ProductFieldUpdate::categories([10, 20]),
        );
    }

    #[Test]
    public function it_does_not_call_transport_on_empty_updates(): void
    {
        $this->transport->shouldNotReceive('put');

        $this->client->update(42);
    }
}
