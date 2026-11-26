<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Sentry;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Sentry\Breadcrumb;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\Span;
use Sentry\Tracing\TransactionContext;
use Tests\TestCase;

/**
 * Drives the client the app itself builds, so the hooks are proven wired, not only correct.
 */
final class SentryUrlQueryWiringTest extends TestCase
{
    private const string DIRTY = 'https://api.example.test/path?apikey=x&sku=y#part';

    private const string CLEAN = 'https://api.example.test/path';

    private CapturingSentryTransport $transport;

    #[Override]
    public function createApplication(): Application
    {
        $this->transport = new CapturingSentryTransport();

        $app = require Application::inferBasePath() . '/bootstrap/app.php';
        $app->booting(function (): void {
            \config([
                'sentry.dsn' => 'https://public@sentry.example.test/1',
                'sentry.traces_sample_rate' => 1.0,
                'sentry.profiles_sample_rate' => 0.0,
                'sentry.transport' => $this->transport,
            ]);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function an_http_client_breadcrumb_is_sent_without_the_query(): void
    {
        Http::fake(['api.example.test/*' => Http::response()]);

        Http::get(self::DIRTY);
        $breadcrumb = $this->sentBreadcrumb('http');

        $this->assertSame(self::CLEAN, $breadcrumb->getMetadata()['url']);
        $this->assertArrayNotHasKey('http.query', $breadcrumb->getMetadata());
        $this->assertArrayNotHasKey('http.fragment', $breadcrumb->getMetadata());
    }

    #[Test]
    public function an_http_client_span_is_sent_without_the_query(): void
    {
        Http::fake(['api.example.test/*' => Http::response()]);
        $hub = \app(HubInterface::class);
        $transaction = $hub->startTransaction(TransactionContext::make()->setName('/test'));
        $hub->setSpan($transaction);

        Http::get(self::DIRTY);
        $transaction->finish();

        $spans = \array_values(\array_filter(
            $this->transport->lastEvent()->getSpans(),
            static fn(Span $span): bool => $span->getOp() === 'http.client',
        ));
        $this->assertCount(1, $spans);
        $this->assertSame(self::CLEAN, $spans[0]->getData('url'));
        $this->assertSame('GET ' . self::CLEAN, $spans[0]->getDescription());
        $this->assertArrayNotHasKey('http.query', $spans[0]->getData());
        $this->assertArrayNotHasKey('http.fragment', $spans[0]->getData());
    }

    #[Test]
    public function a_log_breadcrumb_is_sent_without_any_query(): void
    {
        Log::warning('Retrying ' . self::DIRTY, ['request' => ['url' => self::DIRTY]]);
        $breadcrumb = $this->sentBreadcrumb('log.warning');

        $this->assertSame('Retrying ' . self::CLEAN, $breadcrumb->getMessage());
        $this->assertSame(['url' => self::CLEAN], $breadcrumb->getMetadata()['request']);
    }

    #[Test]
    #[TestWith(['sentry.before_breadcrumb'])]
    #[TestWith(['sentry.before_send'])]
    #[TestWith(['sentry.before_send_transaction'])]
    public function a_registered_callback_survives_config_caching(string $key): void
    {
        $callback = \config($key);
        $this->assertIsObject($callback);
        $path = \storage_path('framework/testing/sentry-callback-' . \getmypid() . '.php');
        \file_put_contents($path, '<?php return ' . \var_export($callback, true) . ';');

        try {
            $restored = require $path;
        } finally {
            \unlink($path);
        }

        $this->assertInstanceOf($callback::class, $restored);
    }

    private function sentBreadcrumb(string $category): Breadcrumb
    {
        $hub = \app(HubInterface::class);
        $hub->withScope(static function (Scope $scope) use ($hub): void {
            $hub->captureMessage('probe');
        });

        $matches = \array_values(\array_filter(
            $this->transport->lastEvent()->getBreadcrumbs(),
            static fn(Breadcrumb $breadcrumb): bool => $breadcrumb->getCategory() === $category,
        ));
        $this->assertCount(1, $matches);

        return $matches[0];
    }
}
