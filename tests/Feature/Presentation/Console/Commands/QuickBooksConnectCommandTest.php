<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\QuickBooks\QuickBooksStateStoreInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Presentation\Console\Commands\QuickBooksConnectCommand;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(QuickBooksConnectCommand::class)]
final class QuickBooksConnectCommandTest extends TestCase
{
    #[Test]
    public function it_prints_a_consent_url_whose_state_the_callback_accepts(): void
    {
        $exit = Artisan::call('quickbooks:connect');
        $url = \array_find(\explode("\n", Artisan::output()), static fn(string $l): bool => \str_starts_with($l, QuickBooksConfig::AUTHORIZE_URL));

        $this->assertSame(0, $exit);
        $this->assertIsString($url);
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $query);
        $this->assertSame('test-client-id', $query['client_id'] ?? null);
        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('com.intuit.quickbooks.accounting', $query['scope'] ?? null);
        $this->assertSame('http://localhost/quickbooks/callback', $query['redirect_uri'] ?? null);
        $state = $query['state'] ?? null;
        $this->assertIsString($state);
        $this->assertTrue($this->app->make(QuickBooksStateStoreInterface::class)->consume($state));
    }

    #[Test]
    public function an_unreachable_state_store_fails_with_a_hint(): void
    {
        $states = Mockery::mock(QuickBooksStateStoreInterface::class);
        $states->shouldReceive('issue')->andThrow(new ExternalServiceUnavailableException('QuickBooks state store'));
        $this->app->instance(QuickBooksStateStoreInterface::class, $states);

        $this->artisan('quickbooks:connect')
            ->expectsOutputToContain('QUICKBOOKS_CACHE_STORE')
            ->assertFailed();
    }
}
