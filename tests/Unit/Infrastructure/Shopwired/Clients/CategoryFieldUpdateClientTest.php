<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Domain\Catalog\Category\ValueObjects\CategoryFieldUpdate;
use App\Infrastructure\Shopwired\Clients\CategoryUpdateClient;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CategoryUpdateClient::class)]
final class CategoryFieldUpdateClientTest extends TestCase
{
    private ShopwiredTransportInterface&MockInterface $transport;

    private CategoryUpdateClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(ShopwiredTransportInterface::class);
        $categoryClient = Mockery::mock(CategoryClientInterface::class);
        $this->client = new CategoryUpdateClient($this->transport, $categoryClient);
    }

    #[Test]
    public function it_builds_correct_payload_for_title(): void
    {
        $this->transport->shouldReceive('put')
            ->once()
            ->with('categories/5', ['title' => 'Electronics']);

        $this->client->update(5, CategoryFieldUpdate::title('Electronics'));
    }

    #[Test]
    public function it_maps_all_fields_correctly(): void
    {
        $this->transport->shouldReceive('put')
            ->once()
            ->with('categories/5', [
                'title' => 'Electronics',
                'description' => 'A great category',
                'metaTitle' => 'SEO Title',
                'metaDescription' => 'SEO Description',
            ]);

        $this->client->update(
            5,
            CategoryFieldUpdate::title('Electronics'),
            CategoryFieldUpdate::description('A great category'),
            CategoryFieldUpdate::metaTitle('SEO Title'),
            CategoryFieldUpdate::metaDescription('SEO Description'),
        );
    }

    #[Test]
    public function it_does_not_call_transport_on_empty_updates(): void
    {
        $this->transport->shouldNotReceive('put');

        $this->client->update(5);
    }
}
