<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Linnworks\LinnworksErrorHandler;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for LinnworksErrorHandler status → Domain exception translation.
 *
 * Focuses on the 400 branch, where Linnworks overloads the status: a genuine
 * malformed request (permanent) vs a transient backend failure (SQL connection
 * timeout) that Linnworks wraps in a 400 and which must stay retryable.
 */
#[CoversClass(LinnworksErrorHandler::class)]
#[Group('unit')]
final class LinnworksErrorHandlerTest extends TestCase
{
    private const string ENDPOINT = '/api/Dashboards/ExecuteCustomScriptQuery';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Handler logs before translating; swallow writes without asserting on them.
        Log::spy();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function transientBadRequestBodies(): iterable
    {
        yield 'pre-login handshake timeout' => [
            'Connection Timeout Expired.  The timeout period elapsed while attempting to consume the '
            . 'pre-login handshake acknowledgement.  This could be because the pre-login handshake failed.',
        ];
        yield 'connection timeout phrase only' => ['Connection Timeout Expired.'];
        yield 'timeout period elapsed phrase only' => ['The timeout period elapsed prior to completion.'];
        yield 'case-insensitive match' => ['connection TIMEOUT expired while connecting.'];
    }

    #[Test]
    #[DataProvider('transientBadRequestBodies')]
    public function it_translates_a_transient_400_into_a_retryable_outage(string $message): void
    {
        $result = LinnworksErrorHandler::handleRequestException(
            self::requestException(400, ['Code' => null, 'Message' => $message]),
            self::ENDPOINT,
        );

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result);
        $this->assertSame('Linnworks', $result->serviceName);
        $this->assertNull($result->retryAfter);
    }

    #[Test]
    public function it_translates_a_genuine_400_into_a_permanent_invalid_request(): void
    {
        $result = LinnworksErrorHandler::handleRequestException(
            self::requestException(400, ['Code' => 'BadRequest', 'Message' => 'fkSupplierId is not a valid GUID']),
            self::ENDPOINT,
        );

        $this->assertInstanceOf(InvalidApiRequestException::class, $result);
        $this->assertSame('fkSupplierId is not a valid GUID', $result->detail);
    }

    #[Test]
    public function it_falls_back_to_a_permanent_invalid_request_when_no_message_present(): void
    {
        $result = LinnworksErrorHandler::handleRequestException(
            self::requestException(400, ['Code' => 'BadRequest']),
            self::ENDPOINT,
        );

        $this->assertInstanceOf(InvalidApiRequestException::class, $result);
        $this->assertSame('Invalid request parameters', $result->detail);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function requestException(int $status, array $body): RequestException
    {
        $response = new Response(
            new Psr7Response($status, ['Content-Type' => 'application/json'], (string) \json_encode($body)),
        );

        return new RequestException($response);
    }
}
