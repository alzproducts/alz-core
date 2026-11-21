<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversNothing]
final class QuickBooksConfigFactoryTest extends TestCase
{
    #[Test]
    public function it_builds_a_sandbox_config_outside_production(): void
    {
        \config(['app.env' => 'local']);

        $config = $this->app->make(QuickBooksConfig::class);

        $this->assertSame(QuickBooksEnvironment::Sandbox, $config->environment);
        $this->assertSame('https://sandbox-quickbooks.api.intuit.com', $config->environment->apiBaseUrl());
        $this->assertSame('test-client-id', $config->clientId);
        $this->assertSame('test-client-secret', $config->clientSecret);
        $this->assertSame('http://localhost/quickbooks/callback', $config->redirectUri);
        $this->assertSame('9130000000000001', $config->realmId);
    }

    #[Test]
    public function it_builds_a_production_config_under_production_app_env(): void
    {
        \config(['app.env' => 'production', 'quickbooks.environment' => 'production']);

        $config = $this->app->make(QuickBooksConfig::class);

        $this->assertSame(QuickBooksEnvironment::Production, $config->environment);
        $this->assertSame('https://quickbooks.api.intuit.com', $config->environment->apiBaseUrl());
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function refusedEnvironments(): iterable
    {
        yield 'production under testing' => ['testing', 'production'];
        yield 'production under local' => ['local', 'production'];
        yield 'production under staging' => ['staging', 'production'];
        yield 'sandbox under production' => ['production', 'sandbox'];
        yield 'unrecognised value' => ['local', 'Sandbox'];
        yield 'blank value' => ['local', ''];
        yield 'missing value' => ['local', null];
    }

    #[Test]
    #[DataProvider('refusedEnvironments')]
    public function it_refuses_a_mismatched_or_unknown_environment_without_sending_a_request(string $appEnv, mixed $quickBooksEnv): void
    {
        Http::fake();
        \config(['app.env' => $appEnv, 'quickbooks.environment' => $quickBooksEnv]);

        try {
            $this->app->make(QuickBooksConfig::class);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertSame('QUICKBOOKS_ENVIRONMENT', $e->configKey);
        }

        Http::assertNothingSent();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function requiredKeys(): iterable
    {
        yield 'client id' => ['quickbooks.client_id', 'QUICKBOOKS_CLIENT_ID'];
        yield 'client secret' => ['quickbooks.client_secret', 'QUICKBOOKS_CLIENT_SECRET'];
        yield 'redirect uri' => ['quickbooks.redirect_uri', 'QUICKBOOKS_REDIRECT_URI'];
        yield 'realm id' => ['quickbooks.realm_id', 'QUICKBOOKS_REALM_ID'];
    }

    #[Test]
    #[DataProvider('requiredKeys')]
    public function it_refuses_a_blank_required_key(string $configKey, string $envVar): void
    {
        \config([$configKey => '']);

        try {
            $this->app->make(QuickBooksConfig::class);
            $this->fail('Expected InvalidConfigurationException');
        } catch (InvalidConfigurationException $e) {
            $this->assertSame($envVar, $e->configKey);
        }
    }
}
