<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\ClickUp;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\ClickUp\ClickUpErrorHandler;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ClickUpErrorHandler::class)]
final class ClickUpErrorHandlerTest extends TestCase
{
    private const string TEST_ENDPOINT = '/list/123/task';

    #[Test]
    #[DataProvider('badRequestStatusProvider')]
    public function it_maps_400_and_422_to_invalid_api_request_exception(int $status): void
    {
        $e = $this->makeRequestException($status);

        $result = ClickUpErrorHandler::handleRequestException($e, self::TEST_ENDPOINT);

        $this->assertInstanceOf(InvalidApiRequestException::class, $result);
        $this->assertSame('ClickUp', $result->serviceName);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function badRequestStatusProvider(): array
    {
        return [
            '400 bad request' => [400],
            '422 unprocessable entity' => [422],
        ];
    }

    #[Test]
    #[DataProvider('authFailureStatusProvider')]
    public function it_maps_401_and_403_to_authentication_expired_exception(int $status): void
    {
        $e = $this->makeRequestException($status);

        $result = ClickUpErrorHandler::handleRequestException($e, self::TEST_ENDPOINT);

        $this->assertInstanceOf(AuthenticationExpiredException::class, $result);
        $this->assertSame('ClickUp', $result->serviceName);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function authFailureStatusProvider(): array
    {
        return [
            '401 unauthorized' => [401],
            '403 forbidden' => [403],
        ];
    }

    #[Test]
    public function it_maps_404_to_resource_not_found_with_unknown_type_and_endpoint_in_context(): void
    {
        $e = $this->makeRequestException(404);

        $result = ClickUpErrorHandler::handleRequestException($e, self::TEST_ENDPOINT);

        $this->assertInstanceOf(ResourceNotFoundException::class, $result);
        $this->assertSame('ClickUp', $result->serviceName);
        $this->assertSame('unknown', $result->resourceType);
        $this->assertSame(self::TEST_ENDPOINT, $result->resourceId);
    }

    #[Test]
    public function it_maps_429_to_external_service_unavailable_exception(): void
    {
        $e = $this->makeRequestException(429);

        $result = ClickUpErrorHandler::handleRequestException($e, self::TEST_ENDPOINT);

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result);
        $this->assertSame('ClickUp', $result->serviceName);
    }

    #[Test]
    #[DataProvider('serverErrorStatusProvider')]
    public function it_maps_5xx_to_external_service_unavailable_exception(int $status): void
    {
        $e = $this->makeRequestException($status);

        $result = ClickUpErrorHandler::handleRequestException($e, self::TEST_ENDPOINT);

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function serverErrorStatusProvider(): array
    {
        return [
            '500 internal server error' => [500],
            '503 service unavailable' => [503],
        ];
    }

    #[Test]
    public function it_maps_connection_exceptions_to_external_service_unavailable(): void
    {
        $e = new ConnectionException('Connection refused', 0);

        $result = ClickUpErrorHandler::handleConnectionException($e, self::TEST_ENDPOINT);

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result);
        $this->assertSame('ClickUp', $result->serviceName);
    }

    #[Test]
    public function it_maps_unexpected_exceptions_to_external_service_unavailable(): void
    {
        $e = new Exception('Unexpected error');

        $result = ClickUpErrorHandler::handleUnexpectedException($e, self::TEST_ENDPOINT);

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result);
    }

    #[Test]
    public function it_maps_unparseable_responses_to_invalid_api_response_exception(): void
    {
        $e = new Exception('JSON parse error');

        $result = ClickUpErrorHandler::handleUnparseableResponse($e);

        $this->assertInstanceOf(InvalidApiResponseException::class, $result);
        $this->assertSame('ClickUp', $result->serviceName);
    }

    private function makeRequestException(int $status): RequestException
    {
        /** @var Response&MockInterface $response */
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('status')->andReturn($status);
        $response->shouldReceive('json')->andReturn([]);
        $response->shouldReceive('header')->with('Retry-After')->andReturn('');

        /** @var RequestException&MockInterface $e */
        $e = Mockery::mock(RequestException::class);
        $e->response = $response;
        $e->shouldReceive('getMessage')->andReturn("HTTP request returned status code {$status}");

        return $e;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
