<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Sentry;

use App\Infrastructure\Sentry\SentryBeforeBreadcrumbCallback;
use App\Infrastructure\Sentry\SentryBeforeSendCallback;
use App\Infrastructure\Sentry\SentryBeforeSendTransactionCallback;
use App\Infrastructure\Sentry\SentryEventScrubber;
use App\Infrastructure\Sentry\SentrySpanScrubber;
use App\Infrastructure\Sentry\SentryUrlQueryStripper;
use Illuminate\Support\Stringable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

#[CoversClass(SentryBeforeBreadcrumbCallback::class)]
#[CoversClass(SentryBeforeSendCallback::class)]
#[CoversClass(SentryBeforeSendTransactionCallback::class)]
#[CoversClass(SentryEventScrubber::class)]
#[CoversClass(SentrySpanScrubber::class)]
#[CoversClass(SentryUrlQueryStripper::class)]
final class SentryBeforeSendCallbackTest extends TestCase
{
    private const string DIRTY = 'https://api.example.test/path?apikey=x&sku=y#part';

    private const string CLEAN = 'https://api.example.test/path';

    #[Test]
    #[TestWith(['https://core.example.test/api/products?page=2', 'https://core.example.test/api/products'])]
    #[TestWith(['https://core.example.test/quickbooks/callback?code=secret-code', 'https://core.example.test/quickbooks/callback'])]
    #[TestWith(['https://core.example.test/quickbooks/%63allback/?code=secret-code', 'https://core.example.test/quickbooks/%63allback/'])]
    public function an_error_event_loses_the_incoming_query_on_any_route(string $url, string $scrubbedUrl): void
    {
        $event = (new SentryBeforeSendCallback())(self::requestEvent(Event::createEvent(), $url), null);

        $this->assertNotNull($event);
        $this->assertSame(['url' => $scrubbedUrl, 'method' => 'GET'], $event->getRequest());
    }

    #[Test]
    public function a_transaction_loses_the_incoming_query(): void
    {
        $url = 'https://core.example.test/api/products?page=2';

        $event = (new SentryBeforeSendTransactionCallback())(self::requestEvent(Event::createTransaction(), $url), null);

        $this->assertSame(['url' => 'https://core.example.test/api/products', 'method' => 'GET'], $event->getRequest());
    }

    #[Test]
    public function a_referer_header_loses_its_query(): void
    {
        $event = Event::createEvent()->setRequest(['headers' => ['referer' => [self::DIRTY]]]);

        $this->assertSame(['headers' => ['referer' => [self::CLEAN]]], SentryEventScrubber::scrub($event)->getRequest());
    }

    #[Test]
    public function an_exception_message_keeps_everything_but_the_query(): void
    {
        $event = Event::createEvent()->setExceptions([new ExceptionDataBag(
            new RuntimeException('cURL error 28: timed out for ' . self::DIRTY . ' after 30s'),
        )]);

        $exceptions = SentryEventScrubber::scrub($event)->getExceptions();

        $this->assertSame('cURL error 28: timed out for ' . self::CLEAN . ' after 30s', $exceptions[0]->getValue());
    }

    #[Test]
    public function stack_frame_arguments_lose_their_queries(): void
    {
        $exception = new ExceptionDataBag(new RuntimeException('failed'), self::stacktrace());
        $event = Event::createEvent()->setExceptions([$exception])->setStacktrace(self::stacktrace());

        $scrubbed = SentryEventScrubber::scrub($event);

        foreach ([$scrubbed->getStacktrace(), $scrubbed->getExceptions()[0]->getStacktrace()] as $stacktrace) {
            $this->assertNotNull($stacktrace);
            $this->assertSame(['url' => self::CLEAN, 'options' => ['base_uri' => self::CLEAN]], $stacktrace->getFrames()[0]->getVars());
        }
    }

    #[Test]
    public function the_message_extra_contexts_and_tags_lose_their_queries(): void
    {
        $event = Event::createEvent()
            ->setMessage('Call to %s failed', [self::DIRTY], 'Call to ' . self::DIRTY . ' failed')
            ->setExtra(['exception_context' => ['url' => self::DIRTY]])
            ->setContext('laravel', ['next' => self::DIRTY])
            ->setTags(['target' => self::DIRTY]);

        $scrubbed = SentryEventScrubber::scrub($event);

        $this->assertSame('Call to %s failed', $scrubbed->getMessage());
        $this->assertSame([self::CLEAN], $scrubbed->getMessageParams());
        $this->assertSame('Call to ' . self::CLEAN . ' failed', $scrubbed->getMessageFormatted());
        $this->assertSame(['exception_context' => ['url' => self::CLEAN]], $scrubbed->getExtra());
        $this->assertSame(['next' => self::CLEAN], $scrubbed->getContexts()['laravel']);
        $this->assertSame(['target' => self::CLEAN], $scrubbed->getTags());
    }

    #[Test]
    #[TestWith(["https://api.example.test/path?q=O'Brien&apikey=x", 'https://api.example.test/path'])]
    #[TestWith(["Client error: `GET https://api.example.test/path?q='a'` resulted in", 'Client error: `GET https://api.example.test/path` resulted in'])]
    #[TestWith(['HTTP://api.example.test/path#access_token=x', 'HTTP://api.example.test/path'])]
    #[TestWith(['{"next":"https:\/\/api.example.test\/path?cursor=x"}', '{"next":"https:\/\/api.example.test\/path"}'])]
    #[TestWith(['https://a.example.test/?x=1 then https://b.example.test?y=2', 'https://a.example.test/ then https://b.example.test'])]
    #[TestWith(['Is https://api.example.test/path up? Yes', 'Is https://api.example.test/path up? Yes'])]
    public function text_loses_every_url_query(string $text, string $expected): void
    {
        $this->assertSame($expected, SentryUrlQueryStripper::text($text));
    }

    #[Test]
    public function an_object_is_stripped_as_the_json_it_would_be_sent_as(): void
    {
        $event = Event::createEvent()->setExtra(['link' => new Stringable(self::DIRTY)]);

        $this->assertSame(['link' => self::CLEAN], SentryEventScrubber::scrub($event)->getExtra());
    }

    #[Test]
    public function a_scrubbed_span_keeps_its_identity(): void
    {
        $span = new Span(SpanContext::make()
            ->setOp('http.client')
            ->setOrigin('auto.http.client')
            ->setDescription('GET ' . self::DIRTY)
            ->setStatus(SpanStatus::ok())
            ->setSampled(true)
            ->setStartTimestamp(1.0)
            ->setEndTimestamp(2.0)
            ->setData(['url' => self::DIRTY, 'http.query' => 'apikey=x', 'http.fragment' => '', 'http.response.status_code' => 200]));

        $scrubbed = SentrySpanScrubber::scrub($span);

        $this->assertSame(['url' => self::CLEAN, 'http.response.status_code' => 200], $scrubbed->getData());
        $this->assertSame('GET ' . self::CLEAN, $scrubbed->getDescription());
        $this->assertSame($span->getSpanId(), $scrubbed->getSpanId());
        $this->assertSame($span->getTraceId(), $scrubbed->getTraceId());
        $this->assertSame([1.0, 2.0], [$scrubbed->getStartTimestamp(), $scrubbed->getEndTimestamp()]);
        $this->assertSame(['http.client', 'auto.http.client'], [$scrubbed->getOp(), $scrubbed->getOrigin()]);
        $this->assertSame($span->getStatus(), $scrubbed->getStatus());
        $this->assertTrue($scrubbed->getSampled());
    }

    private static function stacktrace(): Stacktrace
    {
        return new Stacktrace([new Frame('send', 'Client.php', 1, vars: ['url' => self::DIRTY, 'options' => ['base_uri' => self::DIRTY]])]);
    }

    private static function requestEvent(Event $event, string $url): Event
    {
        $query = (string) \parse_url($url, \PHP_URL_QUERY);

        return $event->setRequest(['url' => $url, 'method' => 'GET', 'query_string' => $query]);
    }
}
