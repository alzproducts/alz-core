<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\Contracts\QuickBooks\QuickBooksEnvironmentInterface;
use App\Infrastructure\QuickBooks\EloquentQuickBooksMappingRepository;
use App\Infrastructure\QuickBooks\QuickBooksClient;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use App\Infrastructure\QuickBooks\QuickBooksMappingCandidateReader;
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

    #[Test]
    public function the_deferred_provider_resolves_the_mapping_repository(): void
    {
        $this->assertContains(QuickBooksMappingRepositoryInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertInstanceOf(EloquentQuickBooksMappingRepository::class, $this->app->make(QuickBooksMappingRepositoryInterface::class));
    }

    #[Test]
    public function the_deferred_provider_resolves_the_mapping_candidates(): void
    {
        $this->assertContains(QuickBooksMappingCandidatesInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertInstanceOf(QuickBooksMappingCandidateReader::class, $this->app->make(QuickBooksMappingCandidatesInterface::class));
    }

    #[Test]
    public function the_realm_is_the_one_bound_config(): void
    {
        $config = new QuickBooksConfig(QuickBooksEnvironment::Production, 'id', 'secret', 'https://example.test/callback', '9130');
        $this->app->instance(QuickBooksConfig::class, $config);

        $this->assertContains(QuickBooksEnvironmentInterface::class, new QuickBooksServiceProvider($this->app)->provides());
        $this->assertSame($config, $this->app->make(QuickBooksEnvironmentInterface::class));
        $this->assertSame('9130', $config->realmId());
    }
}
