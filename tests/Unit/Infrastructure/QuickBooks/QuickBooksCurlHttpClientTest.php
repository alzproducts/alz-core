<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Infrastructure\QuickBooks\QuickBooksCurlHttpClient;
use App\Infrastructure\QuickBooks\QuickBooksFaultMapper;
use App\Infrastructure\QuickBooks\QuickBooksXmlParsing;
use ErrorException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickBooksOnline\API\Core\CoreConstants;
use QuickBooksOnline\API\Core\HttpClients\BaseCurl;
use QuickBooksOnline\API\Core\HttpClients\FaultHandler;
use QuickBooksOnline\API\Core\HttpClients\IntuitResponse;

#[CoversClass(QuickBooksCurlHttpClient::class)]
#[CoversClass(QuickBooksXmlParsing::class)]
final class QuickBooksCurlHttpClientTest extends TestCase
{
    #[Test]
    public function it_verifies_ssl_and_times_out_after_15_seconds_whatever_the_caller_passes(): void
    {
        $curl = new SpyCurl("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n", '{}', 200);

        new QuickBooksCurlHttpClient($curl)->makeAPICall('https://sandbox-quickbooks.api.intuit.com/v3/', 'GET', ['Accept' => 'application/json'], null, null, false);

        $this->assertTrue($curl->options[\CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $curl->options[\CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(CoreConstants::getCertPath(), $curl->options[\CURLOPT_CAINFO]);
        $this->assertSame(15, $curl->options[\CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(15, $curl->options[\CURLOPT_TIMEOUT]);
    }

    #[Test]
    public function the_cert_bundle_is_readable(): void
    {
        $this->assertFileIsReadable(CoreConstants::getCertPath());
    }

    #[Test]
    public function retry_after_is_read_in_any_header_case(): void
    {
        foreach (['Retry-After', 'retry-after', 'RETRY-AFTER'] as $name) {
            $client = new QuickBooksCurlHttpClient(new SpyCurl("HTTP/1.1 429 Too Many Requests\r\n{$name}: 7\r\n\r\n", '', 429));
            $client->makeAPICall('https://example.test', 'GET', ['Accept' => 'application/xml'], null, null, false);

            $this->assertSame(7, $client->retryAfterSeconds(), $name);
        }
    }

    #[Test]
    public function retry_after_is_null_without_the_header_or_without_any_headers(): void
    {
        $withoutHeader = new QuickBooksCurlHttpClient(new SpyCurl("HTTP/1.1 429 Too Many Requests\r\nContent-Type: text/plain\r\n\r\n", '', 429));
        $withoutHeader->makeAPICall('https://example.test', 'GET', ['Accept' => 'application/xml'], null, null, false);

        $noHeaders = new QuickBooksCurlHttpClient(new SpyCurl("HTTP/1.1 429\r\n\r\n", '', 429));
        $noHeaders->makeAPICall('https://example.test', 'GET', ['Accept' => 'application/xml'], null, null, false);

        $this->assertNull($withoutHeader->retryAfterSeconds());
        $this->assertNull($noHeaders->retryAfterSeconds());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function nonXmlErrorBodies(): array
    {
        return [
            '429 plain text' => [429, 'Too Many Requests'],
            '503 truncated xml' => [503, '<IntuitResponse><Fault'],
            '502 empty' => [502, ''],
        ];
    }

    /**
     * Laravel turns PHP warnings into ErrorExceptions, so a parse warning would escape the status-first Fault mapping.
     */
    #[Test]
    #[DataProvider('nonXmlErrorBodies')]
    public function a_non_xml_error_body_with_an_xml_content_type_still_yields_its_fault(int $status, string $body): void
    {
        $client = new QuickBooksCurlHttpClient(new SpyCurl("HTTP/1.1 {$status}\r\nContent-Type: application/xml\r\nRetry-After: 4\r\n\r\n", $body, $status));
        \set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $level, $file, $line);
        });

        try {
            $client->makeAPICall('https://example.test', 'POST', ['Accept' => 'application/xml'], '<x/>', null, false);
        } finally {
            \restore_error_handler();
        }

        $response = $client->getLastResponse();
        $this->assertInstanceOf(IntuitResponse::class, $response);
        $fault = QuickBooksFaultMapper::faultOrNull($response->getFaultHandler());
        $this->assertInstanceOf(FaultHandler::class, $fault);
        $this->assertSame($status, QuickBooksFaultMapper::status($fault));
        $this->assertSame($body, $fault->getResponseBody());
        $this->assertSame(4, $client->retryAfterSeconds());
        $this->assertFalse(\libxml_use_internal_errors());
    }

    #[Test]
    public function retry_after_is_null_before_any_response(): void
    {
        $this->assertNull(new QuickBooksCurlHttpClient(new SpyCurl('', '', 0))->retryAfterSeconds());
    }
}

/**
 * Records curl options and replays a canned response instead of opening a connection.
 */
final class SpyCurl extends BaseCurl
{
    /** @var array<int, mixed> */
    public array $options = [];

    public function __construct(
        private readonly string $rawHeaders,
        private readonly string $body,
        private readonly int $status,
    ) {}

    #[Override]
    public function init(): void {}

    #[Override]
    public function isCurlSet(): bool
    {
        return true;
    }

    /**
     * @param array<int, mixed> $ary
     */
    #[Override]
    public function setupCurlOptArray(array $ary): void
    {
        $this->options = $ary;
    }

    #[Override]
    public function execute(): string
    {
        return $this->rawHeaders . $this->body;
    }

    #[Override]
    public function errno(): int
    {
        return 0;
    }

    #[Override]
    public function error(): string
    {
        return '';
    }

    #[Override]
    public function getInfo($type): int
    {
        return $type === \CURLINFO_HEADER_SIZE ? \mb_strlen($this->rawHeaders) : $this->status;
    }

    #[Override]
    public function close(): void {}
}
