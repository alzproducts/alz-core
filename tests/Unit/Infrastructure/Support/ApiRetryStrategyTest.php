<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Support;

use App\Infrastructure\Support\ApiRetryStrategy;
use Closure;
use Error;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Mockery;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * Test suite for the ApiRetryStrategy class.
 *
 * Verifies the behavior of the defaultRetry() closure for different exception types.
 */
final class ApiRetryStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Disable RequestException message body truncation to avoid PSR-7 mock complexity
        RequestException::$truncateAt = null;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_a_closure(): void
    {
        $result = ApiRetryStrategy::defaultRetry();

        $this->assertInstanceOf(Closure::class, $result);
    }

    #[Test]
    public function it_retries_on_connection_exception(): void
    {
        // Arrange
        $retryClosure = ApiRetryStrategy::defaultRetry();
        $exception = new ConnectionException('Could not connect to host.');

        // Act
        $shouldRetry = $retryClosure($exception);

        // Assert
        $this->assertTrue($shouldRetry);
    }

    #[Test]
    #[DataProvider('serverErrorStatusCodesProvider')]
    public function it_retries_on_request_exception_with_5xx_status_codes(int $statusCode): void
    {
        // Arrange
        $mockResponse = $this->createMockResponse($statusCode, serverError: true);
        $exception = $this->createRequestException($mockResponse);
        $retryClosure = ApiRetryStrategy::defaultRetry();

        // Act
        $shouldRetry = $retryClosure($exception);

        // Assert
        $this->assertTrue($shouldRetry, "Expected retry for status code {$statusCode}");
    }

    #[Test]
    public function it_retries_on_request_exception_with_429_status(): void
    {
        // Arrange
        $mockResponse = $this->createMockResponse(429, serverError: false);
        $exception = $this->createRequestException($mockResponse);
        $retryClosure = ApiRetryStrategy::defaultRetry();

        // Act
        $shouldRetry = $retryClosure($exception);

        // Assert
        $this->assertTrue($shouldRetry);
    }

    #[Test]
    #[DataProvider('clientErrorStatusCodesProvider')]
    public function it_does_not_retry_on_request_exception_with_other_4xx_status_codes(int $statusCode): void
    {
        // Arrange
        $mockResponse = $this->createMockResponse($statusCode, serverError: false);
        $exception = $this->createRequestException($mockResponse);
        $retryClosure = ApiRetryStrategy::defaultRetry();

        // Act
        $shouldRetry = $retryClosure($exception);

        // Assert
        $this->assertFalse($shouldRetry, "Expected NO retry for status code {$statusCode}");
    }

    private function createMockResponse(int $statusCode, bool $serverError): Response
    {
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->shouldReceive('status')->andReturn($statusCode);
        $mockResponse->shouldReceive('serverError')->andReturn($serverError);

        return $mockResponse;
    }

    private function createRequestException(Response $response): RequestException
    {
        try {
            // Suppress exception message generation by setting truncateAt to null
            return new RequestException($response);
        } catch (Throwable $e) {
            // If RequestException construction fails, create a minimal instance
            $exception = new class ($response) extends RequestException {
                public function __construct(Response $response)
                {
                    $this->response = $response;
                }
            };

            return $exception;
        }
    }

    #[Test]
    #[DataProvider('nonHttpExceptionsProvider')]
    public function it_does_not_retry_on_non_http_related_exceptions(Throwable $exception): void
    {
        // Arrange
        $retryClosure = ApiRetryStrategy::defaultRetry();

        // Act
        $shouldRetry = $retryClosure($exception);

        // Assert
        $this->assertFalse($shouldRetry);
    }

    public static function serverErrorStatusCodesProvider(): array
    {
        return [
            '500 Internal Server Error' => [500],
            '502 Bad Gateway' => [502],
            '503 Service Unavailable' => [503],
            '504 Gateway Timeout' => [504],
            '508 Loop Detected' => [508],
            '599 Network Connect Timeout Error' => [599],
        ];
    }

    public static function clientErrorStatusCodesProvider(): array
    {
        return [
            '400 Bad Request' => [400],
            '401 Unauthorized' => [401],
            '403 Forbidden' => [403],
            '404 Not Found' => [404],
            '418 I\'m a teapot' => [418],
            '422 Unprocessable Entity' => [422],
            '428 Precondition Required' => [428],
        ];
    }

    public static function nonHttpExceptionsProvider(): array
    {
        return [
            'Generic Exception' => [new Exception('A generic error occurred.')],
            'PHP Error' => [new Error('A fatal PHP error.')],
            'InvalidArgumentException' => [new InvalidArgumentException('Invalid input was provided.')],
            'OutOfBoundsException' => [new OutOfBoundsException('Array index out of bounds.')],
        ];
    }
}
