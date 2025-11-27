<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Shopwired\Services\CachingShopwiredService;
use App\Presentation\Console\Commands\ShopwiredCacheClearCommand;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ShopwiredCacheClearCommand::class)]
final class ShopwiredCacheClearCommandTest extends TestCase
{
    #[Test]
    public function it_clears_cache_and_returns_success(): void
    {
        // Arrange
        $this->mock(CachingShopwiredService::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('invalidateAll')
                ->once();
        });

        // Act & Assert
        $this->artisan('shopwired:cache-clear')
            ->expectsOutput('ShopWired cache cleared')
            ->assertSuccessful();
    }

    #[Test]
    public function it_calls_invalidate_all_on_service(): void
    {
        // Arrange
        $service = Mockery::mock(CachingShopwiredService::class);
        $service->shouldReceive('invalidateAll')
            ->once()
            ->andReturnNull();

        $this->app->instance(CachingShopwiredService::class, $service);

        // Act
        $exitCode = $this->artisan('shopwired:cache-clear');

        // Assert
        $exitCode->assertExitCode(0);
    }
}
