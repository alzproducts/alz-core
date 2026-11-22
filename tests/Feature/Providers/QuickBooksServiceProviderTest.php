<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Infrastructure\QuickBooks\QuickBooksClient;
use App\Providers\QuickBooksServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversNothing]
final class QuickBooksServiceProviderTest extends TestCase
{
    #[Test]
    public function the_deferred_provider_resolves_order_posting(): void
    {
        $this->assertContains(QuickBooksOrderPostingInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertInstanceOf(QuickBooksClient::class, $this->app->make(QuickBooksOrderPostingInterface::class));
    }
}
