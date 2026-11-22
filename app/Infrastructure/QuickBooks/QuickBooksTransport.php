<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\Support\TransientLogThrottle;
use Carbon\CarbonImmutable;
use Closure;
use Exception;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use QuickBooksOnline\API\Core\HttpClients\FaultHandler;
use QuickBooksOnline\API\DataService\DataService;
use Random\RandomException;
use SensitiveParameter;

/**
 * Accounting API calls through the SDK: the only class that calls it. Each attempt, a 429 retry included, gets a fresh
 * DataService (a reused curl handle keeps stale options), and starts only with a full client timeout left before the
 * deadline and a rate-limiter grant. SDK messages and Fault bodies can hold customer data, so logs carry only the status,
 * class, Intuit code and element, and no Domain exception chains the SDK exception as `previous`.
 */
final readonly class QuickBooksTransport
{
    /** A call must be able to run its full curl timeout before the deadline. */
    public const int START_FLOOR_SECONDS = QuickBooksCurlHttpClient::TIMEOUT_SECONDS;

    public const int BACKOFF_BASE_SECONDS = 2;

    public const int BACKOFF_CAP_SECONDS = 60;

    private const string LOG_PREFIX = 'QuickBooks API';

    private const int TOO_MANY_REQUESTS = 429;

    public function __construct(
        private QuickBooksDataServiceFactory $dataServices,
        private QuickBooksRateLimiter $limiter,
        private TransientLogThrottle $logThrottle,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException      on a 401 or 403, or an Authentication or Authorization Fault
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage, or a 429 past the retry window
     * @throws QuickBooksDeadlineExceededException when the call cannot start in time; nothing was sent
     * @throws QuickBooksFaultException            on any other Fault
     */
    public function companyInfo(#[SensitiveParameter] ApiKeyToken $accessToken, Deadline $deadline): mixed
    {
        return $this->send($accessToken, $deadline, false, static fn(DataService $dataService): mixed => $dataService->getCompanyInfo());
    }

    /**
     * Sends one call, retrying a 429 with back-off while the deadline allows.
     *
     * @param-immediately-invoked-callable $call
     * @param Closure(DataService): mixed $call
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     */
    private function send(#[SensitiveParameter] ApiKeyToken $accessToken, Deadline $deadline, bool $batchCall, Closure $call): mixed
    {
        $this->awaitStart($deadline, $batchCall);

        for ($retry = 0; ; ++$retry) {
            $session = $this->dataServices->create($accessToken);
            $result = $this->attempt($session, $call);

            $error = QuickBooksFaultMapper::faultOrNull($session->dataService->getLastError());
            if ($error === null) {
                return $result;
            }

            if (QuickBooksFaultMapper::status($error) !== self::TOO_MANY_REQUESTS) {
                throw $this->callFailure($error, $session->httpClient);
            }

            $retryAfter = $session->httpClient->retryAfterSeconds();
            $wait = \max(self::backoffSeconds($retry), (float) ($retryAfter ?? 0));
            if (!$this->awaitRetry($deadline, $batchCall, $wait)) {
                throw $this->transientFailure(
                    ExternalServiceUnavailableException::class,
                    self::TOO_MANY_REQUESTS,
                    new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, $retryAfter ?? (int) \ceil($wait)),
                );
            }
        }
    }

    /**
     * @param-immediately-invoked-callable $call
     * @param Closure(DataService): mixed $call
     *
     * @throws ExternalServiceUnavailableException
     */
    private function attempt(QuickBooksDataServiceSession $session, Closure $call): mixed
    {
        $result = null;
        $thrown = null;

        try {
            $result = $call($session->dataService);
        } catch (Exception $e) { // @ignoreException - curl and SDK errors are transport failures; translated below
            $thrown = $e::class;
        }

        // intentional: translated outside the catch so the SDK exception cannot become `previous` (see class doc)
        if ($thrown !== null) {
            throw $this->transientFailure($thrown, null);
        }

        return $result;
    }

    /**
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     */
    private function awaitStart(Deadline $deadline, bool $batchCall): void
    {
        $latestStart = self::latestStart($deadline);
        $secondsLeft = $latestStart->secondsLeft(CarbonImmutable::now());
        if ($secondsLeft < 0) {
            throw new QuickBooksDeadlineExceededException($secondsLeft);
        }

        $this->limiter->acquire($batchCall, $latestStart);
    }

    /**
     * False when the retry cannot start in time or the limiter refuses it: the caller reports rate limiting, keeping the
     * Retry-After hint, rather than a deadline error.
     */
    private function awaitRetry(Deadline $deadline, bool $batchCall, float $waitSeconds): bool
    {
        $latestStart = self::latestStart($deadline);
        if ($latestStart->secondsLeft(CarbonImmutable::now()) < $waitSeconds) {
            return false;
        }

        Sleep::for((int) \ceil($waitSeconds * 1000))->milliseconds();

        try {
            $this->limiter->acquire($batchCall, $latestStart);
        } catch (ExternalServiceUnavailableException|QuickBooksDeadlineExceededException) { // @ignoreException - a refused retry is reported as rate limiting
            return false;
        }

        return true;
    }

    private static function latestStart(Deadline $deadline): Deadline
    {
        return Deadline::at(CarbonImmutable::instance($deadline->at)->subSeconds(self::START_FLOOR_SECONDS));
    }

    /**
     * Exponential with equal jitter: half the step plus up to half again, the step capped at 60 s.
     */
    private static function backoffSeconds(int $retry): float
    {
        $step = (float) \min(self::BACKOFF_CAP_SECONDS, self::BACKOFF_BASE_SECONDS * (2 ** \min($retry, 6)));

        try {
            return $step / 2 + $step / 2 * \random_int(0, 1000) / 1000;
        } catch (RandomException) { // @ignoreException - without a CSPRNG the full step is still a valid back-off
            return $step;
        }
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
