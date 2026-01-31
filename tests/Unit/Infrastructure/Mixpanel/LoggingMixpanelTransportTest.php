<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Mixpanel;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\Mixpanel\Contracts\MixpanelTransportInterface;
use App\Infrastructure\Mixpanel\Enums\MixpanelLogLevel;
use App\Infrastructure\Mixpanel\LoggingMixpanelTransport;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test suite for the LoggingMixpanelTransport decorator.
 *
 * Per TestingStrategy.md: Infrastructure layer needs minimal tests.
 * - One happy path (delegates correctly)
 * - One error path (propagates exceptions)
 */
final class LoggingMixpanelTransportTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_delegates_to_inner_transport_and_logs(): void
    {
        // Arrange
        $innerTransport = Mockery::mock(MixpanelTransportInterface::class);
        $expectedResponse = $this->createMockResponse(200);

        $innerTransport
            ->shouldReceive('request')
            ->once()
            ->with('POST', 'https://api.mixpanel.com/import?project_id=123', '{"event":"test"}', 'application/json', true)
            ->andReturn($expectedResponse);

        Log::shouldReceive('debug')->twice(); // Request + response logs

        $transport = new LoggingMixpanelTransport($innerTransport, MixpanelLogLevel::Info);

        // Act
        $response = $transport->request('POST', 'https://api.mixpanel.com/import?project_id=123', '{"event":"test"}', 'application/json', true);

        // Assert
        $this->assertSame($expectedResponse, $response);
    }

    #[Test]
    public function it_propagates_exceptions_from_inner_transport(): void
    {
        // Arrange
        $innerTransport = Mockery::mock(MixpanelTransportInterface::class);

        $innerTransport
            ->shouldReceive('request')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('Mixpanel'));

        Log::shouldReceive('debug')->once(); // Request log before exception

        $transport = new LoggingMixpanelTransport($innerTransport, MixpanelLogLevel::Info);

        // Assert
        $this->expectException(ExternalServiceUnavailableException::class);

        // Act
        $transport->request('POST', 'https://api.mixpanel.com/import', '{}', 'application/json');
    }

    private function createMockResponse(int $statusCode): Response&MockInterface
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('status')->andReturn($statusCode);
        $response->shouldReceive('body')->andReturn('{}');

        return $response;
    }
}
