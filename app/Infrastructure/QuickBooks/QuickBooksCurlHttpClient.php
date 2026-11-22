<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Infrastructure\Support\RetryAfterParser;
use Override;
use QuickBooksOnline\API\Core\HttpClients\CurlHttpClient;
use QuickBooksOnline\API\Core\HttpClients\IntuitResponse;
use Webmozart\Assert\Assert;

/**
 * The SDK's curl client with its unsafe call arguments replaced: `SyncRestHandler` always passes
 * verifySSL false (host check off) and no timeout. Timeouts are set per instance, never through the
 * SDK's process-wide timeout constant, which Octane would keep for the worker's life.
 */
final class QuickBooksCurlHttpClient extends CurlHttpClient
{
    public const int TIMEOUT_SECONDS = 15;

    private const string RETRY_AFTER = 'retry-after';

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

        return parent::makeAPICall($url, $method, $headers, $body, self::TIMEOUT_SECONDS, true);
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
     * The SDK's PHPDoc promises an array; it holds null when the response had no headers.
     *
     * @return array<mixed>
     */
    private static function headers(mixed $headers): array
    {
        return \is_array($headers) ? $headers : [];
    }
}
