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
 * only with its own curl timeout left before it. SDK messages and Fault bodies can hold customer data, so logs carry only
 * the status, class, Intuit code and element, call name, curl error number and Intuit transaction ID, and no Domain
 * exception chains the SDK exception as `previous`.
 */
final readonly class QuickBooksTransport
{
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
     * @throws UnexpectedApiResultException        on a 2xx Fault
     */
    public function companyInfo(#[SensitiveParameter] ApiKeyToken $accessToken): mixed
    {
        $this->limiter->acquire(false);

        $call = QuickBooksCall::CompanyInfo;
        $session = $this->dataServices->create($accessToken, $call->timeoutSeconds());
        $result = $this->attempt($session, $call, static fn(DataService $dataService): mixed => $dataService->getCompanyInfo());

        $error = QuickBooksFaultMapper::faultOrNull($session->dataService->getLastError());
        if ($error === null && $result === null && QuickBooksFaultMapper::hasFault($session->httpClient->responseBody())) {
            throw $this->unexpectedNullResult('company info', 'CompanyInfo response is a Fault', QuickBooksCallFailure::of($call, $session->httpClient));
        }

        if ($error === null) {
            return $result;
        }

        if (QuickBooksFaultMapper::status($error) === self::TOO_MANY_REQUESTS) {
            throw $this->rateLimited($session->httpClient, $call, 0.0);
        }

        throw $this->callFailure($error, $session->httpClient, $call);
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
     * @throws UnexpectedApiResultException        on a 2xx Fault, or when the response is neither rows nor an empty query response
     */
    public function query(#[SensitiveParameter] ApiKeyToken $accessToken, string $query, QuickBooksCallSpec $spec): array
    {
        $body = null;
        $intuitTid = null;
        $rows = $this->send($accessToken, $spec, false, static function (DataService $dataService, QuickBooksCurlHttpClient $httpClient) use ($query, &$body, &$intuitTid): mixed {
            $result = $dataService->Query($query);
            $body = $httpClient->responseBody();
            $intuitTid = $httpClient->intuitTid();

            return $result;
        });

        // intentional: the SDK also returns null for a 2xx Fault, which it does not record, and for a body that is no query response
        if ($rows === null && !QuickBooksFaultMapper::isZeroRows($body)) {
            throw $this->unexpectedNullResult('query', 'Query response is a Fault or not a query response', new QuickBooksCallFailure($spec->call, $intuitTid));
        }

        if ($rows === null) {
            return [];
        }

        if (!\is_array($rows)) {
            $this->logger->error(self::LOG_PREFIX . ' returned an unexpected query result', ['result_type' => \get_debug_type($rows)] + new QuickBooksCallFailure($spec->call, $intuitTid)->context());

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
    public function add(#[SensitiveParameter] ApiKeyToken $accessToken, IPPIntuitEntity $entity, string $expected, QuickBooksCallSpec $spec): IPPIntuitEntity
    {
        $call = $spec->call;
        $failure = new QuickBooksCallFailure($call, null);
        $created = $this->send($accessToken, $spec, false, static function (DataService $dataService, QuickBooksCurlHttpClient $httpClient) use ($entity, $call, &$failure): mixed {
            try {
                $result = $dataService->Add($entity);
            } catch (IdsException $e) { // @ignoreException - classified below
                $result = $e;
            }

            // intentional: read before the next session, while a curl error still holds the handle open
            $failure = QuickBooksCallFailure::of($call, $httpClient);

            return $result;
        });

        // intentional: only a bare IdsException is the SDK refusing the entity before sending; its subclasses (curl errors among them) are transport failures
        if ($created instanceof IdsException && $created::class !== IdsException::class) {
            throw $this->transientFailure($created::class, null, $failure);
        }

        // intentional: the SDK returns, not throws, an \Exception when it cannot parse the response, so only the sent type counts as created
        if (!$created instanceof $expected) {
            throw $this->unexpectedCreateResult($created, $failure);
        }

        return $created;
    }

    private function unexpectedNullResult(string $kind, string $reason, QuickBooksCallFailure $failure): UnexpectedApiResultException
    {
        $this->logger->error(self::LOG_PREFIX . " returned an unexpected {$kind} result", ['result_type' => 'null'] + $failure->context());

        return new UnexpectedApiResultException(QuickBooksOAuthHttpTransport::SERVICE_NAME, $reason);
    }

    private function unexpectedCreateResult(mixed $created, QuickBooksCallFailure $failure): UnexpectedApiResultException
    {
        $this->logger->error(self::LOG_PREFIX . ' returned an unexpected create result', ['result_type' => \get_debug_type($created)] + $failure->context());

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
        $spec = new QuickBooksCallSpec(QuickBooksCall::BatchCreate, $deadline);
        $this->awaitStart($spec, true);

        for ($retry = 0; ; ++$retry) {
            $session = $this->dataServices->create($accessToken, $spec->call->timeoutSeconds());
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
                return self::batchFailure($this->callFailure($error, $session->httpClient, $spec->call), $session->httpClient->intuitTid());
            }

            $wait = \max(self::backoffSeconds($retry), (float) ($session->httpClient->retryAfterSeconds() ?? 0));
            if (!$this->awaitRetry($spec, true, $wait)) {
                $this->logTransient(ExternalServiceUnavailableException::class, self::TOO_MANY_REQUESTS, QuickBooksCallFailure::of($spec->call, $session->httpClient));

                return QuickBooksBatchExecution::everyItem(BatchItemOutcomeResult::rateLimited($session->httpClient->retryAfterSeconds() ?? (int) \ceil($wait)), $session->httpClient->intuitTid());
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
            $this->logTransient($thrown, null, QuickBooksCallFailure::of(QuickBooksCall::BatchCreate, $session->httpClient));

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
    private function send(#[SensitiveParameter] ApiKeyToken $accessToken, QuickBooksCallSpec $spec, bool $batchCall, Closure $call): mixed
    {
        $this->awaitStart($spec, $batchCall);

        for ($retry = 0; ; ++$retry) {
            $session = $this->dataServices->create($accessToken, $spec->call->timeoutSeconds());
            $result = $this->attempt($session, $spec->call, $call);

            $error = QuickBooksFaultMapper::faultOrNull($session->dataService->getLastError());
            if ($error === null) {
                return $result;
            }

            if (QuickBooksFaultMapper::status($error) !== self::TOO_MANY_REQUESTS) {
                throw $this->callFailure($error, $session->httpClient, $spec->call);
            }

            $retryAfter = $session->httpClient->retryAfterSeconds();
            $wait = \max(self::backoffSeconds($retry), (float) ($retryAfter ?? 0));
            if (!$this->awaitRetry($spec, $batchCall, $wait)) {
                throw $this->rateLimited($session->httpClient, $spec->call, $wait);
            }
        }
    }

    private function rateLimited(QuickBooksCurlHttpClient $httpClient, QuickBooksCall $call, float $wait): ExternalServiceUnavailableException
    {
        $retryAfter = $httpClient->retryAfterSeconds();

        return $this->transientFailure(
            ExternalServiceUnavailableException::class,
            self::TOO_MANY_REQUESTS,
            QuickBooksCallFailure::of($call, $httpClient),
            new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, $retryAfter ?? (int) \ceil($wait)),
        );
    }

    /**
     * @param-immediately-invoked-callable $call
     * @param Closure(DataService, QuickBooksCurlHttpClient): mixed $call
     *
     * @throws ExternalServiceUnavailableException
     */
    private function attempt(QuickBooksDataServiceSession $session, QuickBooksCall $name, Closure $call): mixed
    {
        $result = null;
        $thrown = null;

        try {
            $result = $call($session->dataService, $session->httpClient);
        } catch (Throwable $e) { // @ignoreException - curl and SDK errors, PHP Errors included, are transport failures; translated below
            $thrown = $e::class;
        }

        // intentional: translated outside the catch so the SDK exception cannot become `previous` (see class doc)
        if ($thrown !== null) {
            throw $this->transientFailure($thrown, null, QuickBooksCallFailure::of($name, $session->httpClient));
        }

        return $result;
    }

    /**
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     */
    private function awaitStart(QuickBooksCallSpec $spec, bool $batchCall): void
    {
        $latestStart = self::latestStart($spec);
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
    private function awaitRetry(QuickBooksCallSpec $spec, bool $batchCall, float $waitSeconds): bool
    {
        $latestStart = self::latestStart($spec);
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

    /**
     * A call must be able to run its full curl timeout before the deadline.
     */
    private static function latestStart(QuickBooksCallSpec $spec): Deadline
    {
        return Deadline::at(CarbonImmutable::instance($spec->deadline->at)->subSeconds($spec->call->timeoutSeconds()));
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
        QuickBooksCall $call,
    ): AuthenticationExpiredException|ExternalServiceUnavailableException|QuickBooksFaultException {
        $failure = QuickBooksFaultMapper::callLevel($error, $httpClient->retryAfterSeconds());
        $status = QuickBooksFaultMapper::status($error);
        $callFailure = QuickBooksCallFailure::of($call, $httpClient);

        if ($failure instanceof ExternalServiceUnavailableException) {
            return $this->transientFailure($failure::class, $status, $callFailure, $failure);
        }

        $context = ['status' => $status, 'exception' => $failure::class] + $callFailure->context();
        if ($failure instanceof QuickBooksFaultException) {
            $context += ['code' => $failure->fault->code, 'element' => $failure->fault->element];
        }

        $this->logger->error(self::LOG_PREFIX . ' rejected the request', $context);

        return $failure;
    }

    private function transientFailure(
        string $exceptionClass,
        ?int $status,
        QuickBooksCallFailure $call,
        ?ExternalServiceUnavailableException $failure = null,
    ): ExternalServiceUnavailableException {
        $this->logTransient($exceptionClass, $status, $call);

        return $failure ?? new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME);
    }

    private function logTransient(string $exceptionClass, ?int $status, QuickBooksCallFailure $call): void
    {
        $this->logThrottle->logTransient(QuickBooksOAuthHttpTransport::SERVICE_NAME, self::LOG_PREFIX . ' request failed', [
            'status' => $status,
            'exception' => $exceptionClass,
        ] + $call->context());
    }
}
