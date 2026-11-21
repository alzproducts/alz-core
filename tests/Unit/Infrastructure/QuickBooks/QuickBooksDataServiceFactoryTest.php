<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceFactory;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickBooksOnline\API\Core\HttpClients\SyncRestHandler;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2AccessToken;
use QuickBooksOnline\API\DataService\DataService;
use ReflectionProperty;

/**
 * `*Factory.php` is excluded from coverage, hence CoversNothing.
 */
#[CoversNothing]
final class QuickBooksDataServiceFactoryTest extends TestCase
{
    #[Test]
    public function a_sandbox_data_service_targets_the_configured_company(): void
    {
        $context = $this->build()->getServiceContext();
        $security = $context->IppConfiguration->Security;

        $this->assertSame('https://sandbox-quickbooks.api.intuit.com/v3/', $context->baseserviceURL);
        $this->assertSame('9130000000000001', $context->realmId);
        $this->assertInstanceOf(OAuth2AccessToken::class, $security);
        $this->assertSame('client-id', $security->getClientID());
        $this->assertSame('access-token', $security->getAccessToken());
        $this->assertSame('75', $context->IppConfiguration->minorVersion);
    }

    #[Test]
    public function the_data_service_uses_guzzle_throws_on_error_and_logs_nothing(): void
    {
        $dataService = $this->build();

        $this->assertSame('guzzle', $dataService->getClientName());
        $this->assertTrue($dataService->isThrownExceptionOnError());
        $this->assertFalse(self::restHandler($dataService)->getRequestLogger()->EnableServiceRequestsLogging);
    }

    private function build(): DataService
    {
        $config = new QuickBooksConfig(
            environment: QuickBooksEnvironment::Sandbox,
            clientId: 'client-id',
            clientSecret: 'client-secret',
            redirectUri: 'http://localhost/quickbooks/callback',
            realmId: '9130000000000001',
        );

        return (new QuickBooksDataServiceFactory($config))->create(new ApiKeyToken('access-token'));
    }

    private static function restHandler(DataService $dataService): SyncRestHandler
    {
        $handler = (new ReflectionProperty(DataService::class, 'restHandler'))->getValue($dataService);
        self::assertInstanceOf(SyncRestHandler::class, $handler);

        return $handler;
    }
}
