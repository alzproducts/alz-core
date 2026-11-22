<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksCurlHttpClient;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceFactory;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceSession;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Core\HttpClients\FaultHandler;
use QuickBooksOnline\API\Core\HttpClients\IntuitResponse;
use QuickBooksOnline\API\Data\IPPCompanyInfo;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\IdsException;
use QuickBooksOnline\API\Exception\SdkException;
use Stringable;
use Throwable;

#[CoversClass(QuickBooksTransport::class)]
final class QuickBooksTransportTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const string SENTINEL = 'Sentinel Customer Ltd';

    #[Test]
    public function it_returns_the_sdk_result(): void
    {
        $companyInfo = new IPPCompanyInfo();

        $this->assertSame($companyInfo, $this->transport($this->dataService($companyInfo, false))->companyInfo(self::token(), self::deadline()));
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[Test]
    #[DataProvider('callFaults')]
    public function a_returned_fault_raises_its_mapped_error(int $status, string $body, string $expected): void
    {
        $this->expectException($expected);

        $this->transport($this->dataService(null, self::fault($status, $body)))->companyInfo(self::token(), self::deadline());
    }

    /**
     * @return array<string, array{int, string, class-string<Throwable>}>
     */
    public static function callFaults(): array
    {
        return [
            '401' => [401, '', AuthenticationExpiredException::class],
            '403' => [403, '', AuthenticationExpiredException::class],
            '400 fault' => [400, self::faultXml('6240', 'Name'), QuickBooksFaultException::class],
            '500' => [500, '', ExternalServiceUnavailableException::class],
        ];
    }

    #[Test]
    public function a_400_fault_keeps_its_kind_code_and_element(): void
    {
        try {
            $this->transport($this->dataService(null, self::fault(400, self::faultXml('6240', 'Name'))))->companyInfo(self::token(), self::deadline());
            $this->fail('Expected QuickBooksFaultException');
        } catch (QuickBooksFaultException $e) {
            $this->assertSame(QuickBooksFaultKind::DuplicateCustomerName, $e->fault->kind);
            $this->assertSame('6240', $e->context()['code']);
            $this->assertSame('Name', $e->context()['element']);
        }
    }

    #[Test]
    public function a_429_carries_the_clients_retry_after(): void
    {
        $httpClient = Mockery::mock(QuickBooksCurlHttpClient::class);
        $httpClient->shouldReceive('retryAfterSeconds')->andReturn(9);

        try {
            $this->transport($this->dataService(null, self::fault(429, '')), $httpClient)->companyInfo(self::token(), self::deadline());
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame(9, $e->retryAfter);
        }
    }

    /**
     * @param Throwable $thrown
     */
    #[Test]
    #[DataProvider('thrownErrors')]
    public function a_thrown_sdk_error_is_unavailable_with_nothing_chained(Throwable $thrown): void
    {
        $dataService = Mockery::mock(DataService::class);
        $dataService->shouldReceive('getCompanyInfo')->once()->andThrow($thrown);

        try {
            $this->transport($dataService)->companyInfo(self::token(), self::deadline());
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertNull($e->getPrevious());
        }
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function thrownErrors(): array
    {
        return [
            'curl error' => [new SdkException('cURL error during making API call. cURL Error Number:[28]')],
            'ids error' => [new IdsException('boom')],
        ];
    }

    #[Test]
    public function a_customer_name_in_a_fault_body_reaches_no_log_context_or_previous(): void
    {
        $logger = new RecordingLogger();
        $body = self::faultXml('6240', 'Name', 'Duplicate Name Exists Error', 'The name supplied already exists: ' . self::SENTINEL);

        try {
            $this->transport($this->dataService(null, self::fault(400, $body)), logger: $logger)->companyInfo(self::token(), self::deadline());
            $this->fail('Expected QuickBooksFaultException');
        } catch (QuickBooksFaultException $e) {
            $this->assertStringNotContainsString(self::SENTINEL, $e->getMessage());
            $this->assertStringNotContainsString(self::SENTINEL, \json_encode($e->context(), \JSON_THROW_ON_ERROR));
            $this->assertNull($e->getPrevious());
        }

        $this->assertNotSame([], $logger->records);
        $this->assertStringNotContainsString(self::SENTINEL, \json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    private static function token(): ApiKeyToken
    {
        return new ApiKeyToken('access');
    }

    private static function deadline(): Deadline
    {
        return Deadline::after(new DateTimeImmutable(), 30);
    }

    private static function fault(int $status, string $body): FaultHandler
    {
        $fault = new IntuitResponse("HTTP/1.1 {$status}\r\nContent-Type: application/xml\r\n", $body, $status, true)->getFaultHandler();
        self::assertInstanceOf(FaultHandler::class, $fault);

        return $fault;
    }

    private static function faultXml(string $code, string $element, string $message = 'Rejected', string $detail = ''): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <IntuitResponse xmlns="http://schema.intuit.com/finance/v3">
              <Fault type="ValidationFault">
                <Error code="{$code}" element="{$element}"><Message>{$message}</Message><Detail>{$detail}</Detail></Error>
              </Fault>
            </IntuitResponse>
            XML;
    }

    private function dataService(mixed $result, FaultHandler|false $lastError): DataService
    {
        $dataService = Mockery::mock(DataService::class);
        $dataService->shouldReceive('getCompanyInfo')->once()->andReturn($result);
        $dataService->shouldReceive('getLastError')->andReturn($lastError);

        return $dataService;
    }

    private function transport(
        DataService $dataService,
        ?QuickBooksCurlHttpClient $httpClient = null,
        ?AbstractLogger $logger = null,
    ): QuickBooksTransport {
        $factory = Mockery::mock(QuickBooksDataServiceFactory::class);
        $factory->shouldReceive('create')->once()->andReturn(
            new QuickBooksDataServiceSession($dataService, $httpClient ?? new QuickBooksCurlHttpClient()),
        );

        $logger ??= new NullLogger();
        $throttle = Mockery::mock(TransientLogThrottle::class);
        $throttle->shouldReceive('logTransient')->andReturnUsing(
            static function (string $service, string $message, array $context) use ($logger): void {
                $logger->warning($message, $context);
            },
        );

        return new QuickBooksTransport($factory, $throttle, $logger);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $message, $context];
    }
}
