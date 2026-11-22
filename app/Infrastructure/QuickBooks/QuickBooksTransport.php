<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\Support\TransientLogThrottle;
use Exception;
use Psr\Log\LoggerInterface;
use QuickBooksOnline\API\Core\HttpClients\FaultHandler;
use SensitiveParameter;

/**
 * Accounting API calls through the SDK: the only class that calls it. Each call gets a fresh DataService.
 * SDK messages and Fault bodies can hold customer data, so logs carry only the status, class, Intuit code
 * and element, and no Domain exception chains the SDK exception as `previous`.
 */
final readonly class QuickBooksTransport
{
    private const string LOG_PREFIX = 'QuickBooks API';

    public function __construct(
        private QuickBooksDataServiceFactory $dataServices,
        private TransientLogThrottle $logThrottle,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException     on a 401 or 403, or an Authentication or Authorization Fault
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksFaultException           on any other Fault
     */
    public function companyInfo(#[SensitiveParameter] ApiKeyToken $accessToken, Deadline $deadline): mixed
    {
        $session = $this->dataServices->create($accessToken);
        $result = null;
        $thrown = null;

        try {
            $result = $session->dataService->getCompanyInfo();
        } catch (Exception $e) { // @ignoreException - curl and SDK errors are transport failures; translated below
            $thrown = $e::class;
        }

        // intentional: translated outside the catch so the SDK exception cannot become `previous` (see class doc)
        if ($thrown !== null) {
            throw $this->transientFailure($thrown, null);
        }

        $error = QuickBooksFaultMapper::faultOrNull($session->dataService->getLastError());
        if ($error !== null) {
            throw $this->callFailure($error, $session->httpClient);
        }

        return $result;
    }

    private function callFailure(
        FaultHandler $error,
        QuickBooksCurlHttpClient $httpClient,
    ): AuthenticationExpiredException|ExternalServiceUnavailableException|QuickBooksFaultException {
        $failure = QuickBooksFaultMapper::callLevel($error, $httpClient->retryAfterSeconds());
        $status = QuickBooksFaultMapper::status($error);

        if ($failure instanceof ExternalServiceUnavailableException) {
            return $this->transientFailure($failure::class, $status, $failure);
        }

        $context = ['status' => $status, 'exception' => $failure::class];
        if ($failure instanceof QuickBooksFaultException) {
            $context += ['code' => $failure->fault->code, 'element' => $failure->fault->element];
        }

        $this->logger->error(self::LOG_PREFIX . ' rejected the request', $context);

        return $failure;
    }

    private function transientFailure(
        string $exceptionClass,
        ?int $status,
        ?ExternalServiceUnavailableException $failure = null,
    ): ExternalServiceUnavailableException {
        $this->logThrottle->logTransient(QuickBooksOAuthHttpTransport::SERVICE_NAME, self::LOG_PREFIX . ' request failed', [
            'status' => $status,
            'exception' => $exceptionClass,
        ]);

        return $failure ?? new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME);
    }
}
