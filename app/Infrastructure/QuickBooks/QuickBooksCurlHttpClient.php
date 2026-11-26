<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Infrastructure\Support\RetryAfterParser;
use Override;
use QuickBooksOnline\API\Core\HttpClients\BaseCurl;
use QuickBooksOnline\API\Core\HttpClients\CurlHttpClient;
use QuickBooksOnline\API\Core\HttpClients\IntuitResponse;
use QuickBooksOnline\API\Exception\SdkException;
use Webmozart\Assert\Assert;

/**
 * The SDK's curl client with its unsafe call arguments replaced: `SyncRestHandler` always passes
 * verifySSL false (host check off) and no timeout. Timeouts are set per instance, never through the
 * SDK's process-wide timeout constant, which Octane would keep for the worker's life.
 */
final class QuickBooksCurlHttpClient extends CurlHttpClient
{
    private const string RETRY_AFTER = 'retry-after';

    // intentional: kept here too, because the parent's handle is private and the curl error number lives only on it
    private readonly BaseCurl $curl;

    public function __construct(
        private readonly int $timeoutSeconds,
        ?BaseCurl $curl = null,
    ) {
        $this->curl = $curl ?? new BaseCurl();
        parent::__construct($this->curl);
    }

    /**
     * intentional: variadic, because the parent's six parameters break the excessive-parameter rule.
     * The parent signature is (url, method, headers, body, timeOut, verifySSL); the last two are ignored.
     *
     * @param mixed ...$args
     */
    #[Override]
    public function makeAPICall(mixed ...$args): mixed
    {
        $args = \array_values($args);
        $url = $args[0] ?? null;
        $method = $args[1] ?? null;
        $headers = $args[2] ?? null;
        // intentional: the SDK passes a null body on a GET and never sends a GET body, null or not
        $body = $args[3] ?? ($method === 'GET' ? '' : null);
        Assert::string($url);
        Assert::string($method);
        Assert::isArray($headers);
        Assert::string($body);

        return parent::makeAPICall($url, $method, $headers, $body, $this->timeoutSeconds, true);
    }

    /**
     * @param mixed $response
     */
    #[Override]
    public function setIntuitResponse($response): void
    {
        QuickBooksXmlParsing::quietly(fn(): mixed => parent::setIntuitResponse($response));
    }

    /**
     * The last response's Retry-After in seconds. The SDK keeps header keys in the server's case,
     * may hold null headers, and holds `false` after a curl error.
     */
    public function retryAfterSeconds(): ?int
    {
        $response = $this->getLastResponse();
        if (!$response instanceof IntuitResponse) {
            return null;
        }

        foreach (self::headers($response->getHeaders()) as $name => $value) {
            if (\strcasecmp((string) $name, self::RETRY_AFTER) === 0 && \is_string($value)) {
                return RetryAfterParser::parse(\mb_trim($value));
            }
        }

        return null;
    }

    /**
     * The last call's curl error number, 0 without one. The SDK closes the handle after every call that got a response,
     * and `BaseCurl::errno()` throws on a closed handle, so a closed handle answers 0.
     */
    public function curlErrno(): int
    {
        try {
            return $this->curl->errno();
        } catch (SdkException) { // @ignoreException - a closed handle holds no curl error
            return 0;
        }
    }

    /**
     * The last response's Intuit transaction ID, null without a response or the header.
     */
    public function intuitTid(): ?string
    {
        $response = $this->getLastResponse();

        return $response instanceof IntuitResponse ? self::nonBlank($response->getIntuitTid()) : null;
    }

    /**
     * The last response's raw body, null without a response or when it is empty. It can hold customer data: never log it.
     */
    public function responseBody(): ?string
    {
        $response = $this->getLastResponse();

        return $response instanceof IntuitResponse ? self::nonBlank($response->getBody()) : null;
    }

    /**
     * The SDK's PHPDoc promises a string; it holds null when the response had no such header.
     */
    private static function nonBlank(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The SDK's PHPDoc promises an array; it holds null when the response had no headers.
     *
     * @return array<mixed>
     */
    private static function headers(mixed $headers): array
    {
        return \is_array($headers) ? $headers : [];
    }
}
