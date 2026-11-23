<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
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
use QuickBooksOnline\API\Data\IPPIntuitEntity;
use QuickBooksOnline\API\DataService\Batch;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\IdsException;
use Random\RandomException;
use SensitiveParameter;
use Throwable;

/**
 * Accounting API calls through the SDK: the only class that calls it. Each attempt, a 429 retry included, gets a fresh
 * DataService (a reused curl handle keeps stale options) and a rate-limiter grant; a call given a deadline also starts
 * only with a full client timeout left before it. SDK messages and Fault bodies can hold customer data, so logs carry only the status,
 * class, Intuit code and element, and Intuit transaction ID, and no Domain exception chains the SDK exception as `previous`.
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
     * A plain call: it waits the limiter's fixed bound at most, and a 429 is not retried.
     *
     * @throws AuthenticationExpiredException      on a 401 or 403, or an Authentication or Authorization Fault
     * @throws ExternalServiceUnavailableException on a 5xx, a transport error, a limiter outage or a full limiter, or a 429
     * @throws QuickBooksFaultException            on any other Fault
     */
    public function companyInfo(#[SensitiveParameter] ApiKeyToken $accessToken): mixed
    {
        $this->limiter->acquire(false);

        $session = $this->dataServices->create($accessToken);
        $result = $this->attempt($session, static fn(DataService $dataService): mixed => $dataService->getCompanyInfo());

        $error = QuickBooksFaultMapper::faultOrNull($session->dataService->getLastError());
        if ($error === null) {
            return $result;
        }

        if (QuickBooksFaultMapper::status($error) === self::TOO_MANY_REQUESTS) {
            throw $this->rateLimited($session->httpClient, 0.0);
        }

        throw $this->callFailure($error, $session->httpClient);
    }

    /**
     * Runs one query. The text is never logged: its literals are customer names and SKUs.
     *
     * @return list<object> the matching entities; empty only when QBO answered with zero rows
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException        when the parsed response is neither rows nor empty
     */
    public function query(#[SensitiveParameter] ApiKeyToken $accessToken, string $query, Deadline $deadline): array
    {
        $rows = $this->send($accessToken, $deadline, false, static fn(DataService $dataService): mixed => $dataService->Query($query));

        // intentional: the SDK returns null for a Fault too, but send() has already thrown on the Fault it recorded
        if ($rows === null) {
            return [];
        }

        if (!\is_array($rows)) {
            $this->logger->error(self::LOG_PREFIX . ' returned an unexpected query result', ['result_type' => \get_debug_type($rows)]);

            throw new UnexpectedApiResultException(QuickBooksOAuthHttpTransport::SERVICE_NAME, 'Query result is not a list');
        }

        return \array_values(\array_filter($rows, \is_object(...)));
    }

    /**
     * Creates one entity, outside any batch.
     *
     * @template T of IPPIntuitEntity
     *
     * @param class-string<T> $expected
     *
     * @return T the created entity as QBO returned it
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException        when the response is not the entity type sent
     */
    public function add(#[SensitiveParameter] ApiKeyToken $accessToken, IPPIntuitEntity $entity, string $expected, Deadline $deadline): IPPIntuitEntity
    {
        $intuitTid = null;
        $created = $this->send($accessToken, $deadline, false, static function (DataService $dataService, QuickBooksCurlHttpClient $httpClient) use ($entity, &$intuitTid): mixed {
            try {
                $result = $dataService->Add($entity);
            } catch (IdsException $e) { // @ignoreException - classified below
                $result = $e;
            }

            $intuitTid = $httpClient->intuitTid();

            return $result;
        });

        // intentional: only a bare IdsException is the SDK refusing the entity before sending; its subclasses (curl errors among them) are transport failures
        if ($created instanceof IdsException && $created::class !== IdsException::class) {
            throw $this->transientFailure($created::class, null, $intuitTid);
        }

        // intentional: the SDK returns, not throws, an \Exception when it cannot parse the response, so only the sent type counts as created
        if (!$created instanceof $expected) {
            throw $this->unexpectedCreateResult($created, $intuitTid);
        }

        return $created;
    }

    private function unexpectedCreateResult(mixed $created, ?string $intuitTid): UnexpectedApiResultException
    {
        $this->logger->error(self::LOG_PREFIX . ' returned an unexpected create result', [
            'result_type' => \get_debug_type($created),
            'intuit_tid' => $intuitTid,
        ]);

        return new UnexpectedApiResultException(QuickBooksOAuthHttpTransport::SERVICE_NAME, 'Create result is not the entity sent');
    }

    /**
     * Creates the entities in one batch call, item `i` under bId `i + 1`. Once the call may have reached QBO, a failure is
     * reported per item rather than thrown, so the caller can record what is unknown.
     *
     * @param non-empty-list<IPPIntuitEntity> $entities
     *
     * @throws AuthenticationExpiredException      on a 401 or 403, or an Authentication or Authorization Fault
     * @throws ExternalServiceUnavailableException when the limiter is down before the first send; nothing was sent
     * @throws QuickBooksDeadlineExceededException when the call cannot start in time; nothing was sent
     */
    public function executeBatch(#[SensitiveParameter] ApiKeyToken $accessToken, array $entities, Deadline $deadline): QuickBooksBatchExecution
    {
        $this->awaitStart($deadline, true);

        for ($retry = 0; ; ++$retry) {
            $session = $this->dataServices->create($accessToken);
            $batch = $this->sendBatch($session, $entities);
            if ($batch === null) {
                return QuickBooksBatchExecution::everyItem(BatchItemOutcomeResult::ambiguous(), $session->httpClient->intuitTid());
            }

            $error = QuickBooksFaultMapper::faultOrNull($batch->getLastError());
            if ($error === null) {
                $responses = $batch->ReadOnlyCollection();

                return QuickBooksBatchExecution::responses(\is_array($responses) ? $responses : [], $session->httpClient->intuitTid());
            }

            if (QuickBooksFaultMapper::status($error) !== self::TOO_MANY_REQUESTS) {
                return self::batchFailure($this->callFailure($error, $session->httpClient), $session->httpClient->intuitTid());
            }

            $retryAfter = $session->httpClient->retryAfterSeconds();
            $wait = \max(self::backoffSeconds($retry), (float) ($retryAfter ?? 0));
            if (!$this->awaitRetry($deadline, true, $wait)) {
                $this->logTransient(ExternalServiceUnavailableException::class, self::TOO_MANY_REQUESTS, $session->httpClient->intuitTid());

                return QuickBooksBatchExecution::everyItem(BatchItemOutcomeResult::rateLimited($retryAfter ?? (int) \ceil($wait)), $session->httpClient->intuitTid());
            }
        }
    }

    /**
     * @param non-empty-list<IPPIntuitEntity> $entities
     *
     * @return Batch|null null when building or sending the batch threw
     */
    private function sendBatch(QuickBooksDataServiceSession $session, array $entities): ?Batch
    {
        $batch = null;
        $thrown = null;

        try {
            $batch = $session->dataService->CreateNewBatch();
            foreach ($entities as $index => $entity) {
                $batch->AddEntity($entity, (string) ($index + 1), 'create');
            }

            $batch->Execute();
        } catch (Throwable $e) { // @ignoreException - the SDK throws Exceptions and, on odd Fault codes, TypeErrors; translated below
            $thrown = $e::class;
        }

        // intentional: logged outside the catch so the SDK exception cannot become `previous` (see class doc)
        if ($thrown !== null) {
            $this->logTransient($thrown, null, $session->httpClient->intuitTid());

            return null;
        }

        return $batch;
    }

    /**
     * @throws AuthenticationExpiredException
     */
    private static function batchFailure(
        AuthenticationExpiredException|ExternalServiceUnavailableException|QuickBooksFaultException $failure,
        ?string $intuitTid,
    ): QuickBooksBatchExecution {
        return match (true) {
            $failure instanceof AuthenticationExpiredException => throw $failure,
            $failure instanceof QuickBooksFaultException => QuickBooksBatchExecution::everyItem(BatchItemOutcomeResult::faulted($failure->fault), $intuitTid),
            default => QuickBooksBatchExecution::everyItem(BatchItemOutcomeResult::ambiguous(), $intuitTid),
        };
    }

    /**
     * Sends one call, retrying a 429 with back-off while the deadline allows.
     *
     * @param-immediately-invoked-callable $call
     * @param Closure(DataService, QuickBooksCurlHttpClient): mixed $call
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
                throw $this->rateLimited($session->httpClient, $wait);
            }
        }
    }

    private function rateLimited(QuickBooksCurlHttpClient $httpClient, float $wait): ExternalServiceUnavailableException
    {
        $retryAfter = $httpClient->retryAfterSeconds();

        return $this->transientFailure(
            ExternalServiceUnavailableException::class,
            self::TOO_MANY_REQUESTS,
            $httpClient->intuitTid(),
            new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, $retryAfter ?? (int) \ceil($wait)),
        );
    }

    /**
     * @param-immediately-invoked-callable $call
     * @param Closure(DataService, QuickBooksCurlHttpClient): mixed $call
     *
     * @throws ExternalServiceUnavailableException
     */
    private function attempt(QuickBooksDataServiceSession $session, Closure $call): mixed
    {
        $result = null;
        $thrown = null;

        try {
            $result = $call($session->dataService, $session->httpClient);
        } catch (Exception $e) { // @ignoreException - curl and SDK errors are transport failures; translated below
            $thrown = $e::class;
        }

        // intentional: translated outside the catch so the SDK exception cannot become `previous` (see class doc)
        if ($thrown !== null) {
            throw $this->transientFailure($thrown, null, $session->httpClient->intuitTid());
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

        $this->limiter->acquireBy($batchCall, $latestStart);
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
            $this->limiter->acquireBy($batchCall, $latestStart);
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
            return $this->transientFailure($failure::class, $status, $httpClient->intuitTid(), $failure);
        }

        $context = ['status' => $status, 'exception' => $failure::class, 'intuit_tid' => $httpClient->intuitTid()];
        if ($failure instanceof QuickBooksFaultException) {
            $context += ['code' => $failure->fault->code, 'element' => $failure->fault->element];
        }

        $this->logger->error(self::LOG_PREFIX . ' rejected the request', $context);

        return $failure;
    }

    private function transientFailure(
        string $exceptionClass,
        ?int $status,
        ?string $intuitTid,
        ?ExternalServiceUnavailableException $failure = null,
    ): ExternalServiceUnavailableException {
        $this->logTransient($exceptionClass, $status, $intuitTid);

        return $failure ?? new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME);
    }

    private function logTransient(string $exceptionClass, ?int $status, ?string $intuitTid): void
    {
        $this->logThrottle->logTransient(QuickBooksOAuthHttpTransport::SERVICE_NAME, self::LOG_PREFIX . ' request failed', [
            'status' => $status,
            'exception' => $exceptionClass,
            'intuit_tid' => $intuitTid,
        ]);
    }
}
