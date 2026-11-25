<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Logging;

use App\Infrastructure\Logging\GrafanaLogHandler;
use App\Infrastructure\Logging\GrafanaLogRedactor;
use App\Infrastructure\Logging\Listeners\FlushGrafanaLogsListener;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonSerializable;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\HorizonServiceProvider;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Supervisor;
use Laravel\Octane\Events\RequestTerminated;
use Mockery;
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

#[CoversClass(GrafanaLogHandler::class)]
#[CoversClass(GrafanaLogRedactor::class)]
#[CoversClass(FlushGrafanaLogsListener::class)]
final class GrafanaLogShippingTest extends TestCase
{
    private const string URL = 'https://logs.example.test/loki/api/v1/push';

    private const array CREDENTIALS = [
        'GRAFANA_LOGS_URL' => self::URL,
        'GRAFANA_LOGS_USER' => '123',
        'GRAFANA_LOGS_KEY' => 'write-key',
    ];

    private const array MANAGED = [
        'GRAFANA_LOGS_URL', 'GRAFANA_LOGS_USER', 'GRAFANA_LOGS_KEY', 'GRAFANA_LOGS_LEVEL',
        'RAILWAY_SERVICE_NAME', 'APP_ENV', 'LOG_LEVEL',
    ];

    /** @var array<string, array{mixed, mixed, string|false}> */
    private array $originalEnv = [];

    private string $stderrPath;

    #[Override]
    protected function setUp(): void
    {
        foreach (self::MANAGED as $name) {
            $this->originalEnv[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, \getenv($name)];
        }

        parent::setUp();

        $this->stderrPath = (string) \tempnam(\sys_get_temp_dir(), 'stderr');
        Http::fake([self::URL => Http::response(status: 204)]);
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

        Http::assertSentCount(1);
        Http::assertSent(static fn(ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === self::URL
            && $request->isJson()
            && $request->header('Authorization') === ['Basic ' . \base64_encode('123:write-key')]
            && \array_keys($request->data()) === ['streams']
            && \array_keys($request->data()['streams'][0]) === ['stream', 'values']);
    }

    #[Test]
    public function it_sends_the_record_as_a_json_line_with_its_own_time(): void
    {
        $this->configure();

        $this->grafanaHandler()->handle(new LogRecord(
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
    public function it_groups_a_batch_into_one_stream_per_level(): void
    {
        $this->configure();

        Log::channel('grafana')->info('first');
        Log::channel('grafana')->error('second');
        Log::channel('grafana')->info('third');
        $this->flush();

        $streams = $this->sentStreams();

        self::assertSame(['info', 'error'], \array_column(\array_column($streams, 'stream'), 'level'));
        self::assertCount(2, $streams[0]['values']);
        self::assertCount(1, $streams[1]['values']);
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

    #[Test]
    #[DataProvider('failingStatuses')]
    public function a_rejected_batch_raises_nothing_and_is_not_sent_again(int $status): void
    {
        $this->configure(['GRAFANA_LOGS_URL' => 'https://failing.example.test/push']);
        Http::fake(['https://failing.example.test/*' => Http::sequence()->pushStatus($status)->pushStatus(204)]);

        Log::channel('grafana')->info('lost');
        $this->flush();
        Log::channel('grafana')->info('kept');
        $this->flush();

        Http::assertSentCount(2);
        self::assertSame(['kept'], $this->sentMessages(1));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failingStatuses(): array
    {
        return ['4xx' => [429], '5xx' => [503]];
    }

    #[Test]
    public function a_batch_whose_send_throws_is_not_sent_again(): void
    {
        $this->configure(['GRAFANA_LOGS_URL' => 'https://throwing.example.test/push']);
        $attempts = 0;
        Http::fake(['https://throwing.example.test/*' => static function () use (&$attempts) {
            $attempts++;

            return $attempts === 1 ? throw new RuntimeException('send failed') : Http::response(status: 204);
        }]);

        Log::channel('grafana')->info('lost');
        $this->flush();
        Log::channel('grafana')->info('kept');
        $this->flush();

        self::assertSame(2, $attempts);
        self::assertSame(['kept'], $this->sentMessages());
    }

    #[Test]
    public function a_refused_connection_raises_nothing(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = \stream_socket_get_name($server, false);
        \fclose($server);

        $this->assertFlushReturnsWithin(3.0, "http://{$address}/push");
    }

    #[Test]
    public function a_send_that_is_never_answered_returns_within_three_seconds_and_raises_nothing(): void
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);

        $elapsed = $this->assertFlushReturnsWithin(3.0, 'http://' . \stream_socket_get_name($server, false) . '/push');

        self::assertGreaterThan(1.0, $elapsed);
        \fclose($server);
    }

    #[Test]
    #[DataProvider('redactedKeys')]
    public function it_redacts_listed_keys_at_any_depth_of_context_and_extra(string $key): void
    {
        $this->configure();
        $values = [$key => 'sensitive', 'outer' => ['inner' => [$key => 'sensitive']]];
        $expected = [$key => '[REDACTED]', 'outer' => ['inner' => [$key => '[REDACTED]']]];

        $this->grafanaHandler()->handle($this->record('Redacted', context: $values, extra: $values));
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
            'password' => ['user_password', 'userPassword'],
            'secret' => ['client_secret', 'clientSecret'],
            'token' => ['access_token', 'accessToken'],
            'authorization' => ['proxy_authorization', 'proxyAuthorization', 'Authorization'],
            'cookie' => ['session_cookie', 'sessionCookie'],
            'api_key' => ['apiKey', 'vendor_api_key', 'vendorApiKey'],
            'credential' => ['vendor_credential', 'vendorCredential'],
            'signature' => ['webhook_signature', 'webhookSignature'],
            'response' => ['vendor_response', 'vendorResponse'],
            'raw_response' => ['rawResponse', 'vendor_raw_response', 'vendorRawResponse'],
            'ip' => ['client_ip', 'clientIp'],
            'ip_hash' => ['ipHash', 'client_ip_hash', 'clientIpHash'],
            'ip_address' => ['ipAddress', 'client_ip_address', 'clientIpAddress'],
            'email_hash' => ['emailHash', 'customer_email_hash', 'customerEmailHash'],
        ];

        foreach ($forms as $key => $variants) {
            foreach ([$key, ...$variants] as $form) {
                yield $form => [$form];
            }
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
            'zip' => 'AB1', 'tokens' => 3, 'response_code' => 200, 0 => 'list',
        ];

        Log::channel('grafana')->info('Kept', $context);
        $this->flush();

        self::assertSame($context, $this->sentLines()[0]['context']);
    }

    #[Test]
    public function it_drops_only_the_record_the_redactor_throws_on(): void
    {
        $this->configure();
        $unredactable = new class implements JsonSerializable {
            public function jsonSerialize(): never
            {
                throw new RuntimeException('cannot serialise');
            }
        };

        Log::channel('grafana')->info('before');
        Log::channel('grafana')->info('dropped', ['value' => $unredactable]);
        Log::channel('grafana')->info('after');
        $this->flush();

        Http::assertSentCount(1);
        self::assertSame(['before', 'after'], $this->sentMessages());
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
    public function it_sends_the_first_hundred_records_when_one_more_arrives(): void
    {
        $this->configure();

        foreach (\range(1, 100) as $number) {
            Log::channel('grafana')->info("record {$number}");
        }
        Http::assertNothingSent();

        Log::channel('grafana')->info('record 101');

        Http::assertSentCount(1);
        self::assertSame(
            \array_map(static fn(int $number): string => "record {$number}", \range(1, 100)),
            $this->sentMessages(),
        );
    }

    #[Test]
    public function it_sends_buffered_records_when_one_arrives_with_the_oldest_sixty_seconds_old(): void
    {
        $this->configure();
        $handler = $this->grafanaHandler();
        $start = new DateTimeImmutable('2026-01-01T00:00:00+00:00');

        $handler->handle($this->record('oldest', $start));
        $handler->handle($this->record('newer', $start->modify('+59 seconds')));
        Http::assertNothingSent();

        $handler->handle($this->record('arriving', $start->modify('+60 seconds')));

        Http::assertSentCount(1);
        self::assertSame(['oldest', 'newer'], $this->sentMessages());
    }

    #[Test]
    #[DataProvider('flushEvents')]
    public function it_sends_buffered_records_on_process_lifecycle_events(Closure $event): void
    {
        $this->configure();
        $this->silenceHorizonListeners();
        Log::channel('grafana')->info('buffered');
        Http::assertNothingSent();

        Event::dispatch($event($this->app));

        Http::assertSentCount(1);
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
            'Horizon supervisor looped' => [static fn() => new SupervisorLooped(Mockery::mock(Supervisor::class))],
            'Horizon master supervisor looped' => [static fn() => new MasterSupervisorLooped(Mockery::mock(MasterSupervisor::class))],
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

    private function silenceHorizonListeners(): void
    {
        $events = [SupervisorLooped::class, MasterSupervisorLooped::class];
        $listeners = \array_intersect_key(
            (new class ($this->app) extends HorizonServiceProvider {
                /** @return array<class-string, list<class-string>> */
                public function eventMap(): array
                {
                    return $this->events;
                }
            })->eventMap(),
            \array_flip($events),
        );

        foreach (\array_merge(...\array_values($listeners)) as $listener) {
            $this->app->instance($listener, Mockery::spy($listener));
        }
    }

    private function grafanaHandler(): GrafanaLogHandler
    {
        $handler = Log::channel('grafana')->getLogger()->getHandlers()[0];
        self::assertInstanceOf(GrafanaLogHandler::class, $handler);

        return $handler;
    }

    private function flush(): void
    {
        $this->grafanaHandler()->flush();
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
        return Http::recorded()[$request][0]->data()['streams'];
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
