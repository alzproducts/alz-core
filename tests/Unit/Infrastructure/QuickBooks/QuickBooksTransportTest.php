<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceFactory;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Data\IPPCompanyInfo;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\SdkException;
use QuickBooksOnline\API\Exception\ServiceException;
use RuntimeException;
use Throwable;

#[CoversClass(QuickBooksTransport::class)]
final class QuickBooksTransportTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    public function it_returns_the_sdk_result(): void
    {
        $companyInfo = new IPPCompanyInfo();

        $this->assertSame($companyInfo, $this->transportReturning($companyInfo)->companyInfo(new ApiKeyToken('access')));
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[Test]
    #[DataProvider('sdkFaults')]
    public function it_translates_every_sdk_fault(Throwable $fault, string $expected): void
    {
        $this->expectException($expected);

        $this->transportThrowing($fault)->companyInfo(new ApiKeyToken('access'));
    }

    /**
     * @return array<string, array{Throwable, class-string<Throwable>}>
     */
    public static function sdkFaults(): array
    {
        return [
            'guzzle 401' => [self::guzzleFault(401), AuthenticationExpiredException::class],
            'guzzle 403' => [self::guzzleFault(403), AuthenticationExpiredException::class],
            'guzzle 400' => [self::guzzleFault(400), ExternalServiceUnavailableException::class],
            'guzzle 429' => [self::guzzleFault(429), ExternalServiceUnavailableException::class],
            'guzzle 500' => [self::guzzleFault(500), ExternalServiceUnavailableException::class],
            'sdk network error' => [new SdkException('Network Error:cURL error 28'), ExternalServiceUnavailableException::class],
            'service exception 401' => [new ServiceException('Unauthorized', 401), AuthenticationExpiredException::class],
            'raw guzzle connect failure' => [
                new ConnectException('Connection refused', new Request('GET', 'https://sandbox-quickbooks.api.intuit.com')),
                ExternalServiceUnavailableException::class,
            ],
            'other exception' => [new RuntimeException('boom'), ExternalServiceUnavailableException::class],
        ];
    }

    /**
     * Built the way the SDK's GuzzleHttpClient wraps a RequestException with a response.
     */
    private static function guzzleFault(int $status): SdkException
    {
        return new SdkException(
            'A networking error occurs during Guzzle client request:' . Message::toString(new Response($status, [], '<IntuitResponse/>')),
        );
    }

    private function transportReturning(mixed $result): QuickBooksTransport
    {
        $dataService = Mockery::mock(DataService::class);
        $dataService->shouldReceive('getCompanyInfo')->once()->andReturn($result);

        return $this->transport($dataService);
    }

    private function transportThrowing(Throwable $fault): QuickBooksTransport
    {
        $dataService = Mockery::mock(DataService::class);
        $dataService->shouldReceive('getCompanyInfo')->once()->andThrow($fault);

        return $this->transport($dataService);
    }

    private function transport(DataService $dataService): QuickBooksTransport
    {
        $factory = Mockery::mock(QuickBooksDataServiceFactory::class);
        $factory->shouldReceive('create')->once()->andReturn($dataService);

        return new QuickBooksTransport($factory, Mockery::mock(TransientLogThrottle::class)->shouldIgnoreMissing(), new NullLogger());
    }
}
