<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\HelpScout\Support\SdkExceptionTranslator;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use HelpScout\Api\Exception\RateLimitExceededException;
use Illuminate\Support\Facades\Log;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * SdkExceptionTranslator — SDK/Guzzle failure → Domain exception mapping.
 *
 * Failures are built the way the SDK handler stack raises them: rate limits as
 * RateLimitExceededException, other non-401 4xx/5xx via RequestException::create().
 */
#[CoversClass(SdkExceptionTranslator::class)]
#[Group('unit')]
final class SdkExceptionTranslatorTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();
    }

    #[Test]
    public function it_returns_the_operation_result(): void
    {
        $this->assertSame(99, SdkExceptionTranslator::execute(static fn(): int => 99, 'conversation creation'));
    }

    #[Test]
    public function it_translates_a_rate_limit_into_a_transient_failure_honouring_retry_after(): void
    {
        $result = self::translate(new RateLimitExceededException(
            'Rate limit exceeded',
            self::request(),
            new Response(429, ['Retry-After' => '42']),
        ));

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result);
        $this->assertSame('HelpScout', $result->serviceName);
        $this->assertSame(42, $result->retryAfter);
    }

    #[Test]
    public function it_translates_a_rate_limit_without_retry_after_into_a_transient_failure(): void
    {
        $result = self::translate(new RateLimitExceededException(
            'Rate limit exceeded',
            self::request(),
            new Response(429),
        ));

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result);
        $this->assertNull($result->retryAfter);
    }

    #[Test]
    public function it_translates_a_server_error_into_a_transient_failure(): void
    {
        $result = self::translate(RequestException::create(self::request(), new Response(503)));

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result);
        $this->assertSame('HelpScout', $result->serviceName);
        $this->assertNull($result->retryAfter);
    }

    #[Test]
    public function it_translates_a_not_found_client_error_into_an_invalid_request(): void
    {
        $result = self::translate(RequestException::create(self::request(), new Response(404)));

        $this->assertInstanceOf(InvalidApiRequestException::class, $result);
        $this->assertSame('HelpScout', $result->serviceName);
        $this->assertSame('Request rejected with HTTP 404', $result->detail);
    }

    #[Test]
    public function it_translates_a_connection_failure_into_a_transient_failure(): void
    {
        $result = self::translate(new ConnectException('Connection timed out', self::request()));

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $result);
        $this->assertNull($result->retryAfter);
    }

    private static function translate(Throwable $sdkFailure): Throwable
    {
        try {
            SdkExceptionTranslator::execute(static fn(): never => throw $sdkFailure, 'conversation creation');
        } catch (Throwable $translated) {
            self::assertSame($sdkFailure, $translated->getPrevious());

            return $translated;
        }

        self::fail('Expected the translator to throw');
    }

    private static function request(): Request
    {
        return new Request('POST', 'https://api.helpscout.net/v2/conversations');
    }
}
