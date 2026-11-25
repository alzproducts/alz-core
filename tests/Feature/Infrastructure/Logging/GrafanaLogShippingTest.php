<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Logging;

use App\Application\Contracts\ErrorReporterInterface;
use App\Infrastructure\Logging\GrafanaLogChannel;
use App\Infrastructure\Logging\GrafanaLogRedactor;
use App\Infrastructure\Logging\GrafanaLogSender;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonSerializable;
use Laravel\Octane\Events\RequestTerminated;
use Monolog\Handler\BufferHandler;
use Monolog\JsonSerializableDateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Throwable;

#[CoversClass(GrafanaLogChannel::class)]
#[CoversClass(GrafanaLogRedactor::class)]
#[CoversClass(GrafanaLogSender::class)]
final class GrafanaLogShippingTest extends TestCase
{
    private const string URL = 'https://logs.example.test/loki/api/v1/push';

    private const array CREDENTIALS = [
        'GRAFANA_LOGS_URL' => self::URL,
        'GRAFANA_LOGS_USER' => 'loki-user',
        'GRAFANA_LOGS_KEY' => 'write-key',
    ];

    private const array MANAGED = [
        'GRAFANA_LOGS_URL', 'GRAFANA_LOGS_USER', 'GRAFANA_LOGS_KEY', 'GRAFANA_LOGS_LEVEL',
        'RAILWAY_SERVICE_NAME', 'APP_ENV', 'LOG_LEVEL',
    ];

    /** @var array<string, array{mixed, mixed, string|false}> */
    private array $originalEnv = [];

    private string $stderrPath;

    /** @var list<array{Throwable, array<string, mixed>}> */
    private array $reports = [];

    private Closure $endpoint;

    /** @var list<ClientRequest> */
    private array $requests = [];

    #[Override]
    protected function setUp(): void
    {
        foreach (self::MANAGED as $name) {
            $this->originalEnv[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, \getenv($name)];
        }

        parent::setUp();

        $this->stderrPath = (string) \tempnam(\sys_get_temp_dir(), 'stderr');
        $this->endpoint = static fn() => Http::response(status: 204);
        Http::fake(function (ClientRequest $request) {
            $this->requests[] = $request;

            return ($this->endpoint)();
        });
        $this->reportTo(function (Throwable $exception, array $context): void {
            $this->reports[] = [$exception, $context];
        });
    }

    #[Override]
    protected function tearDown(): void
    {
        @\unlink($this->stderrPath);

        foreach ($this->originalEnv as $name => [$env, $server, $process]) {
            unset($_ENV[$name], $_SERVER[$name]);

            if ($env !== null) {
                $_ENV[$name] = $env;
            }

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }

            \putenv($process === false ? $name : "{$name}={$process}");
        }

        parent::tearDown();
    }

    #[Test]
    public function it_posts_flushed_records_to_loki_with_basic_auth(): void
    {
        $this->configure();

        Log::channel('grafana')->info('Sync finished');
        $this->flush();

        self::assertCount(1, $this->requests);
        Http::assertSent(static fn(ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL
            && $request->isJson()
            && $request->header('Authorization') === ['Basic ' . \base64_encode('loki-user:write-key')]
            && \array_keys($request->data()) === ['streams']
            && \array_keys($request->data()['streams'][0]) === ['stream', 'values']);
    }

    #[Test]
    public function it_sends_the_record_as_a_json_line_with_its_own_time(): void
    {
        $this->configure();

        $this->bufferHandler()->handle(new LogRecord(
            datetime: new DateTimeImmutable('2026-01-02T03:04:05.678901+00:00'),
            channel: 'orders',
            level: Level::Warning,
            message: 'Stock low',
            context: ['order_id' => 7, 'ratio' => 1.0, 'email' => 'staff@example.test'],
            extra: ['trace_id' => 'abc'],
        ));
        $this->flush();

        [$time, $line] = $this->sentStreams()[0]['values'][0];
        $decoded = \json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('1767323045678901000', $time);
        self::assertSame('Stock low', $decoded['message']);
        self::assertSame('warn', $decoded['level']);
        self::assertSame('orders', $decoded['channel']);
        self::assertSame(['order_id' => 7, 'ratio' => 1.0, 'email' => 'staff@example.test'], $decoded['context']);
        self::assertSame(['trace_id' => 'abc'], $decoded['extra']);
    }

    #[Test]
    #[DataProvider('levelLabels')]
    public function it_labels_streams_by_app_env_service_and_level(Level $level, string $label): void
    {
        $this->configure(['RAILWAY_SERVICE_NAME' => 'worker', 'APP_ENV' => 'staging', 'GRAFANA_LOGS_LEVEL' => 'debug']);

        Log::channel('grafana')->log($level->toPsrLogLevel(), 'Labelled');
        $this->flush();

        self::assertEquals(
            ['app' => 'alz-core', 'env' => 'staging', 'service' => 'worker', 'level' => $label],
            $this->sentStreams()[0]['stream'],
        );
    }

    /**
     * @return array<string, array{Level, string}>
     */
    public static function levelLabels(): array
    {
        return [
            'debug' => [Level::Debug, 'debug'],
            'info' => [Level::Info, 'info'],
            'notice' => [Level::Notice, 'info'],
            'warning' => [Level::Warning, 'warn'],
            'error' => [Level::Error, 'error'],
            'critical' => [Level::Critical, 'error'],
            'alert' => [Level::Alert, 'error'],
            'emergency' => [Level::Emergency, 'error'],
        ];
    }

    #[Test]
    public function it_leaves_out_the_service_label_without_a_railway_service_name(): void
    {
        $this->configure();

        Log::channel('grafana')->info('Labelled');
        $this->flush();

        self::assertEquals(
            ['app' => 'alz-core', 'env' => 'testing', 'level' => 'info'],
            $this->sentStreams()[0]['stream'],
        );
    }

    /**
     * @param list<string> $blank
     */
    #[Test]
    #[DataProvider('blankCredentials')]
    public function it_boots_and_sends_nothing_when_a_credential_is_blank(array $blank): void
    {
        $this->setEnv([...self::CREDENTIALS, ...\array_fill_keys($blank, '')]);
        $this->refreshApplication();
        Http::fake();

        self::assertTrue($this->app?->isBooted());

        Log::channel('grafana')->error('Not sent');
        $this->flush();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function blankCredentials(): array
    {
        return [
            'url' => [['GRAFANA_LOGS_URL']],
            'user' => [['GRAFANA_LOGS_USER']],
            'key' => [['GRAFANA_LOGS_KEY']],
            'all' => [['GRAFANA_LOGS_URL', 'GRAFANA_LOGS_USER', 'GRAFANA_LOGS_KEY']],
        ];
    }

    /**
     * @param 'connection'|'timeout'|'4xx'|'5xx'|'redactor' $failure
     * @param array<string, int|string> $details
     */
    #[Test]
    #[DataProvider('failures')]
    public function a_failure_is_reported_once_without_record_content_or_credentials(string $failure, array $details): void
    {
        $this->configure();

        $this->failWith($failure);

        $lines = $this->stderrLines();
        self::assertCount(1, $lines);
        self::assertSame('error', $lines[0]['level']);
        self::assertSame($details, $lines[0]['context']);
        self::assertCount(1, $this->reports);
        [$exception, $context] = $this->reports[0];
        self::assertNull($exception->getPrevious());

        foreach (['lost', 'sensitive-context', ...\array_values(self::CREDENTIALS)] as $secret) {
            self::assertStringNotContainsString($secret, \stripslashes((string) \file_get_contents($this->stderrPath)));
            self::assertStringNotContainsString($secret, $exception->getMessage());
            self::assertStringNotContainsString($secret, \json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        $this->travel(60)->seconds();
        $this->failWith($failure);
        $this->travel(60)->seconds();
        $this->failWith($failure);

        self::assertCount(1, $this->stderrLines());
        self::assertCount(1, $this->reports);
    }

    /**
     * @param 'connection'|'timeout'|'4xx'|'5xx'|'redactor' $failure
     */
    #[Test]
    #[DataProvider('failureKinds')]
    public function a_failure_drops_batches_unsent_for_sixty_seconds(string $failure): void
    {
        $this->freezeTime();
        $this->configure();
        $this->failWith($failure);
        $requests = \count($this->requests);

        $this->travel(59)->seconds();
        Log::channel('grafana')->info('dropped');
        $this->flush();
        Log::channel('grafana')->info('kept');

        self::assertCount($requests, $this->requests);

        $this->travel(1)->seconds();
        $this->flush();
        Log::channel('grafana')->info('next');
        $this->flush();

        self::assertCount($requests + 2, $this->requests);
        self::assertSame(['kept'], $this->sentMessages($requests));
        self::assertSame(['next'], $this->sentMessages($requests + 1));
    }

    /**
     * @param 'connection'|'timeout'|'4xx'|'5xx'|'redactor' $failure
     */
    #[Test]
    #[DataProvider('failureKinds')]
    public function recovery_is_logged_and_the_next_failure_is_reported_again(string $failure): void
    {
        $this->configure();

        Log::channel('grafana')->info('sent');
        $this->flush();
        self::assertSame([], $this->stderrLines());

        $this->failWith($failure);
        $this->travel(60)->seconds();
        Log::channel('grafana')->info('sent');
        $this->flush();
        Log::channel('grafana')->info('sent');
        $this->flush();

        self::assertSame(['error', 'info'], \array_column($this->stderrLines(), 'level'));
        self::assertCount(1, $this->reports);

        $this->failWith($failure);

        self::assertSame(['error', 'info', 'error'], \array_column($this->stderrLines(), 'level'));
        self::assertCount(2, $this->reports);
    }

    /**
     * @return array<string, array{string, array<string, int|string>}>
     */
    public static function failures(): array
    {
        return [
            'connection error' => ['connection', ['exception' => ConnectionException::class, 'dropped' => 2]],
            'timeout' => ['timeout', ['exception' => ConnectionException::class, 'dropped' => 2]],
            '4xx' => ['4xx', ['status' => 401, 'dropped' => 2]],
            '5xx' => ['5xx', ['status' => 503, 'dropped' => 2]],
            'redactor' => ['redactor', ['exception' => RuntimeException::class, 'dropped' => 2]],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function failureKinds(): array
    {
        return \array_map(static fn(array $failure): array => [$failure[0]], self::failures());
    }

    #[Test]
    public function a_redactor_failure_sends_no_record_of_its_batch(): void
    {
        $this->configure();

        $this->failWith('redactor');

        self::assertSame([], $this->requests);
    }

    #[Test]
    public function a_failure_in_one_application_clone_pauses_another(): void
    {
        $this->configure();
        $this->app->make('log');
        $clones = [clone $this->app, clone $this->app];
        $this->endpoint = static fn() => Http::response(status: 503);

        foreach ($clones as $clone) {
            $logs = $clone->make('log');
            self::assertInstanceOf(LogManager::class, $logs);
            $logs->setApplication($clone);
            $logs->channel('grafana')->info('lost');
            $logs->channel('grafana')->reset();
        }

        self::assertCount(1, $this->requests);
    }

    #[Test]
    public function failure_and_recovery_records_are_not_sent_to_grafana(): void
    {
        $this->configure();
        \config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['stderr', 'grafana']]);

        $this->failWith('5xx');

        foreach (['first', 'second'] as $message) {
            $this->travel(60)->seconds();
            Log::info($message);
            $this->flush();
        }

        self::assertSame(['first'], $this->sentMessages(1));
        self::assertSame(['second'], $this->sentMessages(2));
    }

    #[Test]
    public function a_failing_report_raises_nothing(): void
    {
        $this->configure();
        \config(['logging.channels.stderr.handler_with.stream' => '/dev/null/unwritable']);
        $this->reportTo(function (Throwable $exception, array $context): never {
            $this->reports[] = [$exception, $context];

            throw $exception;
        });

        $this->failWith('5xx');

        self::assertCount(1, $this->reports);
    }

    #[Test]
    public function a_refused_connection_raises_nothing(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = \stream_socket_get_name($server, false);
        \fclose($server);

        $this->assertFlushReturnsWithin(2.0, "http://{$address}/push");
    }

    #[Test]
    public function a_send_that_is_never_answered_returns_within_two_seconds_and_raises_nothing(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);

        $elapsed = $this->assertFlushReturnsWithin(2.0, 'http://' . \stream_socket_get_name($server, false) . '/push');

        self::assertGreaterThan(0.5, $elapsed);
        \fclose($server);
    }

    #[Test]
    #[DataProvider('redactedKeys')]
    public function it_redacts_listed_keys_at_any_depth_of_context_and_extra(string $key): void
    {
        $this->configure();
        $values = [$key => 'sensitive', 'outer' => ['inner' => [$key => 'sensitive']]];
        $expected = [$key => '[REDACTED]', 'outer' => ['inner' => [$key => '[REDACTED]']]];

        $this->bufferHandler()->handle($this->record('Redacted', context: $values, extra: $values));
        $this->flush();

        $line = $this->sentLines()[0];
        self::assertSame($expected, $line['context']);
        self::assertSame($expected, $line['extra']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function redactedKeys(): iterable
    {
        $forms = [
            'password' => 'user_password',
            'secret' => 'client_secret',
            'token' => 'access_token',
            'authorization' => 'proxy_authorization',
            'cookie' => 'session_cookie',
            'api_key' => 'vendor_api_key',
            'credential' => 'vendor_credential',
            'signature' => 'webhook_signature',
            'response' => 'vendor_response',
            'raw_response' => 'vendor_raw_response',
            'ip' => 'client_ip',
            'ip_hash' => 'client_ip_hash',
            'ip_address' => 'client_ip_address',
            'email_hash' => 'customer_email_hash',
        ];

        foreach ($forms as $key => $suffixed) {
            yield $key => [$key];
            yield $suffixed => [$suffixed];
        }
    }

    #[Test]
    public function it_redacts_arrays_under_listed_keys_and_keys_inside_objects(): void
    {
        $this->configure();

        Log::channel('grafana')->info('Redacted', [
            'response' => ['body' => 'vendor body'],
            'object' => (object) ['ip' => '192.0.2.1', 'zip' => 'AB1'],
        ]);
        $this->flush();

        self::assertSame(
            ['response' => '[REDACTED]', 'object' => ['ip' => '[REDACTED]', 'zip' => 'AB1']],
            $this->sentLines()[0]['context'],
        );
    }

    #[Test]
    public function it_sends_unlisted_keys_as_logged(): void
    {
        $this->configure();
        $context = [
            'email' => 'staff@example.test', 'user_agent' => 'curl', 'user_id' => 1, 'customer_id' => 2,
            'zip' => 'AB1', 'clientIp' => 'kept', 'tokens' => 3, 'response_code' => 200, 0 => 'list',
        ];

        Log::channel('grafana')->info('Kept', $context);
        $this->flush();

        self::assertSame($context, $this->sentLines()[0]['context']);
    }

    #[Test]
    public function stacking_grafana_leaves_the_stderr_line_unchanged(): void
    {
        $this->configure();
        $context = ['ip' => '192.0.2.1', 'name' => '{ip}'];

        $time = new JsonSerializableDateTimeImmutable(true);

        Log::channel('stderr')->getLogger()->addRecord(Level::Info, 'From {ip} as {name}', $context, $time);
        Log::stack(['stderr', 'grafana'])->getLogger()->addRecord(Level::Info, 'From {ip} as {name}', $context, $time);
        $this->flush();

        [$alone, $stacked] = (array) \file($this->stderrPath);

        self::assertSame($alone, $stacked);
        self::assertStringContainsString('"ip":"192.0.2.1"', (string) $stacked);
        self::assertSame('[REDACTED]', $this->sentLines()[0]['context']['ip']);
    }

    #[Test]
    #[DataProvider('levelThresholds')]
    public function it_sends_records_at_or_above_its_own_level(?string $threshold, string $below, string $at): void
    {
        $this->configure($threshold === null ? [] : ['GRAFANA_LOGS_LEVEL' => $threshold]);

        Log::channel('grafana')->log($below, 'below');
        Log::channel('grafana')->log($at, 'at');
        $this->flush();

        self::assertSame(['at'], $this->sentMessages());
    }

    /**
     * @return array<string, array{?string, string, string}>
     */
    public static function levelThresholds(): array
    {
        return [
            'unset' => [null, 'debug', 'info'],
            'warning' => ['warning', 'info', 'warning'],
        ];
    }

    #[Test]
    public function the_production_security_channel_reaches_stderr_and_grafana(): void
    {
        $this->configure(['APP_ENV' => 'production']);

        Log::channel('security')->warning('Sign-in refused');
        $this->flush();

        self::assertSame(['Sign-in refused'], $this->sentMessages());
        self::assertSame('production', $this->sentStreams()[0]['stream']['env']);

        $lines = $this->stderrLines();
        self::assertCount(1, $lines);
        self::assertSame('Sign-in refused', $lines[0]['message']);
        self::assertSame('warn', $lines[0]['level']);
        self::assertArrayNotHasKey('level_name', $lines[0]);
    }

    #[Test]
    public function it_sends_the_first_five_hundred_records_when_one_more_arrives(): void
    {
        $this->configure();

        foreach (\range(1, 500) as $number) {
            Log::channel('grafana')->info("record {$number}");
        }
        self::assertSame([], $this->requests);

        Log::channel('grafana')->info('record 501');

        self::assertCount(1, $this->requests);
        self::assertSame(
            \array_map(static fn(int $number): string => "record {$number}", \range(1, 500)),
            $this->sentMessages(),
        );
    }

    #[Test]
    #[DataProvider('flushEvents')]
    public function it_sends_buffered_records_on_process_lifecycle_events(Closure $event): void
    {
        $this->configure();
        Log::channel('grafana')->info('buffered');
        self::assertSame([], $this->requests);

        Event::dispatch($event($this->app));

        self::assertCount(1, $this->requests);
        self::assertSame(['buffered'], $this->sentMessages());
    }

    /**
     * @return array<string, array{Closure}>
     */
    public static function flushEvents(): array
    {
        return [
            'Octane request terminated' => [static fn(Application $app) => new RequestTerminated(
                $app,
                $app,
                Request::create('/up'),
                new Response(),
            )],
            'queue worker looping' => [static fn() => new Looping('redis', 'default')],
            'queue worker stopping' => [static fn() => new WorkerStopping()],
        ];
    }

    /**
     * @param array<string, string> $env
     */
    private function configure(array $env = []): void
    {
        $this->setEnv([...self::CREDENTIALS, ...$env]);

        \config(['logging' => require \config_path('logging.php')]);
        \config(['logging.channels.stderr.handler_with.stream' => $this->stderrPath]);
    }

    /**
     * @param array<string, string> $env
     */
    private function setEnv(array $env): void
    {
        foreach (self::MANAGED as $name) {
            $value = $env[$name] ?? ($name === 'APP_ENV' ? 'testing' : null);
            unset($_ENV[$name], $_SERVER[$name]);
            \putenv($name);

            if ($value !== null) {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }

    /**
     * @param 'connection'|'timeout'|'4xx'|'5xx'|'redactor' $failure
     */
    private function failWith(string $failure): void
    {
        $working = $this->endpoint;
        $this->endpoint = static fn() => match ($failure) {
            'connection' => throw new ConnectionException('cURL error 7: refused for ' . \implode(' ', self::CREDENTIALS)),
            'timeout' => throw new ConnectionException('cURL error 28: timed out for ' . \implode(' ', self::CREDENTIALS)),
            '4xx' => Http::response('rejected: lost', 401),
            default => Http::response('rejected: lost', 503),
        };
        $unredactable = new class implements JsonSerializable {
            public function jsonSerialize(): never
            {
                throw new RuntimeException('cannot serialise: lost');
            }
        };

        Log::channel('grafana')->info('lost one', ['order' => 'sensitive-context']);
        Log::channel('grafana')->info('lost two', $failure === 'redactor' ? ['value' => $unredactable] : []);
        $this->flush();
        $this->endpoint = $working;
    }

    private function reportTo(Closure $report): void
    {
        $this->app->instance(ErrorReporterInterface::class, new readonly class ($report) implements ErrorReporterInterface {
            public function __construct(private Closure $report) {}

            public function report(Throwable $exception, array $context = []): void
            {
                ($this->report)($exception, $context);
            }
        });
    }

    private function bufferHandler(): BufferHandler
    {
        $handler = Log::channel('grafana')->getLogger()->getHandlers()[0];
        self::assertInstanceOf(BufferHandler::class, $handler);

        return $handler;
    }

    private function flush(): void
    {
        Log::channel('grafana')->reset();
    }

    private function assertFlushReturnsWithin(float $seconds, string $url): float
    {
        $this->app?->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::allowStrayRequests();
        $this->configure(['GRAFANA_LOGS_URL' => $url]);
        $startedAt = \microtime(true);

        Log::channel('grafana')->info('Lost');
        $this->flush();

        $elapsed = \microtime(true) - $startedAt;
        self::assertLessThan($seconds, $elapsed);

        return $elapsed;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private function record(
        string $message,
        DateTimeImmutable $time = new DateTimeImmutable(),
        array $context = [],
        array $extra = [],
    ): LogRecord {
        return new LogRecord($time, 'test', Level::Info, $message, $context, $extra);
    }

    /**
     * @return list<array{stream: array<string, string>, values: list<array{string, string}>}>
     */
    private function sentStreams(int $request = 0): array
    {
        return $this->requests[$request]->data()['streams'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentLines(int $request = 0): array
    {
        $lines = [];

        foreach ($this->sentStreams($request) as $stream) {
            foreach ($stream['values'] as [, $line]) {
                $lines[] = \json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function sentMessages(int $request = 0): array
    {
        return \array_column($this->sentLines($request), 'message');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stderrLines(): array
    {
        return \array_map(
            static fn(string $line): array => \json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            \file($this->stderrPath, FILE_IGNORE_NEW_LINES) ?: [],
        );
    }
}
