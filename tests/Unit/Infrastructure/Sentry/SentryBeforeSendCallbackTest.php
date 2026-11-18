<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Sentry;

use App\Infrastructure\Sentry\SentryBeforeSendCallback;
use App\Infrastructure\Sentry\SentryBeforeSendTransactionCallback;
use App\Infrastructure\Sentry\SentryRequestScrubber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

#[CoversClass(SentryBeforeSendCallback::class)]
#[CoversClass(SentryBeforeSendTransactionCallback::class)]
#[CoversClass(SentryRequestScrubber::class)]
final class SentryBeforeSendCallbackTest extends TestCase
{
    private const string CALLBACK_URL = 'https://core.example.test/quickbooks/callback?code=secret-code&state=abc&realmId=1';

    #[Test]
    public function an_error_event_from_the_callback_loses_its_query(): void
    {
        $event = (new SentryBeforeSendCallback())(self::requestEvent(Event::createEvent(), self::CALLBACK_URL), null);

        $this->assertNotNull($event);
        $this->assertSame(['url' => 'https://core.example.test/quickbooks/callback', 'method' => 'GET'], $event->getRequest());
    }

    #[Test]
    public function a_transaction_from_the_callback_loses_its_query(): void
    {
        $event = (new SentryBeforeSendTransactionCallback())(self::requestEvent(Event::createTransaction(), self::CALLBACK_URL), null);

        $this->assertNotNull($event);
        $this->assertSame(['url' => 'https://core.example.test/quickbooks/callback', 'method' => 'GET'], $event->getRequest());
    }

    #[Test]
    #[TestWith(['https://core.example.test/quickbooks/callback/?code=secret-code', 'https://core.example.test/quickbooks/callback/'])]
    #[TestWith(['https://core.example.test/quickbooks/%63allback?code=secret-code', 'https://core.example.test/quickbooks/%63allback'])]
    public function a_router_equivalent_callback_path_also_loses_its_query(string $url, string $scrubbedUrl): void
    {
        $event = (new SentryBeforeSendCallback())(self::requestEvent(Event::createEvent(), $url), null);

        $this->assertNotNull($event);
        $this->assertSame(['url' => $scrubbedUrl, 'method' => 'GET'], $event->getRequest());
    }

    #[Test]
    public function other_routes_keep_their_query(): void
    {
        $url = 'https://core.example.test/api/products?page=2';

        $event = (new SentryBeforeSendTransactionCallback())(self::requestEvent(Event::createTransaction(), $url), null);

        $this->assertNotNull($event);
        $this->assertSame(['url' => $url, 'method' => 'GET', 'query_string' => 'page=2'], $event->getRequest());
    }

    private static function requestEvent(Event $event, string $url): Event
    {
        $query = (string) \parse_url($url, \PHP_URL_QUERY);

        return $event->setRequest(['url' => $url, 'method' => 'GET', 'query_string' => $query]);
    }
}
