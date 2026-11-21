<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\Support\TransientLogThrottle;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use QuickBooksOnline\API\Exception\IdsException;
use SensitiveParameter;
use Throwable;

/**
 * Accounting API calls through the SDK. Owns every SDK exception translation (plan D11).
 * SDK exception messages embed the raw HTTP response, so only the status and class are logged.
 */
final readonly class QuickBooksTransport
{
    private const string LOG_PREFIX = 'QuickBooks API';

    private const string STATUS_PATTERN = '#HTTP/\d(?:\.\d)? (\d{3})#';

    public function __construct(
        private QuickBooksDataServiceFactory $dataServices,
        private TransientLogThrottle $logThrottle,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException     on a 401 or 403
     * @throws ExternalServiceUnavailableException
     */
    public function companyInfo(#[SensitiveParameter] ApiKeyToken $accessToken): mixed
    {
        try {
            return $this->dataServices->create($accessToken)->getCompanyInfo();
        } catch (IdsException $e) {
            throw $this->sdkFailure($e);
        } catch (GuzzleException $e) {
            throw $this->transientFailure($e, 'connection failed', null);
        } catch (Exception $e) {
            throw $this->transientFailure($e, 'unexpected error', null);
        }
    }

    private function sdkFailure(IdsException $e): AuthenticationExpiredException|ExternalServiceUnavailableException
    {
        $status = self::httpStatus($e);

        if ($status === 401 || $status === 403) {
            $this->logger->error(self::LOG_PREFIX . ' rejected the access token', ['status' => $status]);

            return new AuthenticationExpiredException(
                QuickBooksOAuthHttpTransport::SERVICE_NAME,
                'QuickBooks rejected the access token; reconnect with quickbooks:connect if this persists',
                $e,
            );
        }

        if ($status === null || $status === 429 || $status >= 500) {
            return $this->transientFailure($e, 'request failed', $status);
        }

        $this->logger->error(self::LOG_PREFIX . ' request returned an unexpected status', [
            'status' => $status,
            'exception' => $e::class,
        ]);

        return new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, previous: $e);
    }

    private function transientFailure(Throwable $e, string $event, ?int $status): ExternalServiceUnavailableException
    {
        $this->logThrottle->logTransient(QuickBooksOAuthHttpTransport::SERVICE_NAME, self::LOG_PREFIX . ' ' . $event, [
            'status' => $status,
            'exception' => $e::class,
        ]);

        return new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, previous: $e);
    }

    /**
     * Under the Guzzle client an HTTP fault is an `SdkException` whose message carries the raw status line (ledger P12).
     */
    private static function httpStatus(IdsException $e): ?int
    {
        $code = $e->getCode();
        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        return \preg_match(self::STATUS_PATTERN, $e->getMessage(), $matches) === 1 ? (int) $matches[1] : null;
    }
}
