<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksCurlHttpClient;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceFactory;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceSession;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use App\Infrastructure\QuickBooks\QuickBooksRestHandlerSeam;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use QuickBooksOnline\API\Core\CoreConstants;
use QuickBooksOnline\API\Core\HttpClients\SyncRestHandler;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2AccessToken;
use QuickBooksOnline\API\DataService\DataService;
use ReflectionClass;
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
    public function the_rest_handler_sends_through_the_hardened_client_with_logging_off(): void
    {
        $session = $this->session();
        $handler = self::restHandler($session->dataService);

        $this->assertSame(CoreConstants::CLIENT_CURL, $session->dataService->getClientName());
        $this->assertFalse($session->dataService->isThrownExceptionOnError());
        $this->assertSame($session->httpClient, $handler->getHttpClientInterface());
        $this->assertFalse($handler->getRequestLogger()->EnableServiceRequestsLogging);
    }

    #[Test]
    #[TestWith([15])]
    #[TestWith([60])]
    public function the_session_client_times_out_after_the_timeout_it_was_created_with(int $timeoutSeconds): void
    {
        $this->assertSame($timeoutSeconds, new ReflectionProperty(QuickBooksCurlHttpClient::class, 'timeoutSeconds')->getValue($this->session($timeoutSeconds)->httpClient));
    }

    #[Test]
    public function the_seam_fails_closed_without_a_rest_handler(): void
    {
        $dataService = new ReflectionClass(DataService::class)->newInstanceWithoutConstructor();

        $this->expectException(InvalidConfigurationException::class);

        QuickBooksRestHandlerSeam::install($dataService, new QuickBooksCurlHttpClient(15));
    }

    private function build(): DataService
    {
        return $this->session()->dataService;
    }

    private function session(int $timeoutSeconds = 15): QuickBooksDataServiceSession
    {
        $config = new QuickBooksConfig(
            environment: QuickBooksEnvironment::Sandbox,
            clientId: 'client-id',
            clientSecret: 'client-secret',
            redirectUri: 'http://localhost/quickbooks/callback',
            realmId: '9130000000000001',
        );

        return (new QuickBooksDataServiceFactory($config))->create(new ApiKeyToken('access-token'), $timeoutSeconds);
    }

    /**
     * intentional: reads the SDK property by name, so a rename in an SDK upgrade fails here.
     */
    private static function restHandler(DataService $dataService): SyncRestHandler
    {
        $handler = (new ReflectionProperty(DataService::class, 'restHandler'))->getValue($dataService);
        self::assertInstanceOf(SyncRestHandler::class, $handler);

        return $handler;
    }
}
