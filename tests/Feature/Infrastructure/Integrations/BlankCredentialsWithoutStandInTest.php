<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Integrations;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\ReviewsIoClientInterface;
use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Linnworks\LinnworksClientFactory;
use App\Infrastructure\Shopwired\ShopwiredClientFactory;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integrations without a stand-in keep failing on use when their credentials are blank (ADR-0015).
 */
#[CoversNothing]
final class BlankCredentialsWithoutStandInTest extends TestCase
{
    private const array BLANK_VENDOR_CREDENTIALS = [
        'shopwired.api_key' => '',
        'shopwired.api_secret' => '',
        'linnworks.application_id' => '',
        'linnworks.application_secret' => '',
        'linnworks.installation_token' => '',
        'mixpanel.service_account_username' => '',
        'mixpanel.service_account_password' => '',
        'mixpanel.project_id' => '',
        'reviewsio.api_key' => '',
        'reviewsio.store_id' => '',
    ];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        ShopwiredClientFactory::reset();
        LinnworksClientFactory::reset();
        $this->app->detectEnvironment(static fn(): string => 'local');
        \config(self::BLANK_VENDOR_CREDENTIALS);
        Http::fake();
    }

    #[Override]
    protected function tearDown(): void
    {
        ShopwiredClientFactory::reset();
        LinnworksClientFactory::reset();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function clientsWithoutAStandIn(): iterable
    {
        yield 'ShopWired' => [OrderClientInterface::class];
        yield 'Linnworks' => [InventoryClientInterface::class];
        yield 'Mixpanel' => [MixpanelClientInterface::class];
        yield 'Reviews.io' => [ReviewsIoClientInterface::class];
    }

    /**
     * @param class-string $abstract
     */
    #[Test]
    #[DataProvider('clientsWithoutAStandIn')]
    public function resolving_the_client_throws_and_sends_nothing(string $abstract): void
    {
        try {
            $this->app->make($abstract);
            $this->fail("{$abstract} resolved with blank credentials.");
        } catch (InvalidConfigurationException) {
            Http::assertNothingSent();
        }
    }
}
