<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Support;

use App\Infrastructure\Support\TransientLogThrottle;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(TransientLogThrottle::class)]
final class TransientLogThrottleTest extends TestCase
{
    private CacheManager&MockInterface $mockCache;

    private LoggerInterface&MockInterface $mockLogger;

    private Repository&MockInterface $mockStore;

    private TransientLogThrottle $throttle;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockCache = Mockery::mock(CacheManager::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockStore = Mockery::mock(Repository::class);

        $this->mockCache->shouldReceive('store')
            ->withNoArgs()
            ->andReturn($this->mockStore)
            ->byDefault();

        $this->throttle = new TransientLogThrottle(
            $this->mockCache,
            $this->mockLogger,
        );
    }

    #[Test]
    public function first_failure_logs_at_error_with_suppression_note(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('transient_log_throttle:linnworks:escalation', 0)
            ->once()
            ->andReturn(0);

        $this->mockStore->shouldReceive('add')
            ->with('transient_log_throttle:linnworks', true, 5 * 60)
            ->once()
            ->andReturnTrue();

        $this->mockStore->shouldReceive('put')
            ->with('transient_log_throttle:linnworks:escalation', 1, 60 * 60)
            ->once();

        $this->mockLogger->shouldReceive('error')
            ->with('Linnworks API failed', Mockery::on(
                static fn(array $ctx): bool => $ctx['status'] === 500
                    && $ctx['note'] === 'Subsequent transient failures suppressed for 5 minutes',
            ))
            ->once();

        $this->throttle->logTransient('linnworks', 'Linnworks API failed', ['status' => 500]);
    }

    #[Test]
    public function subsequent_failure_within_window_logs_at_warning(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('transient_log_throttle:shopwired:escalation', 0)
            ->once()
            ->andReturn(0);

        $this->mockStore->shouldReceive('add')
            ->with('transient_log_throttle:shopwired', true, 5 * 60)
            ->once()
            ->andReturnFalse();

        $this->mockStore->shouldNotReceive('put');

        $this->mockLogger->shouldReceive('warning')
            ->with('ShopWired API failed', ['status' => 503])
            ->once();

        $this->throttle->logTransient('shopwired', 'ShopWired API failed', ['status' => 503]);
    }

    #[Test]
    public function exponential_backoff_doubles_window_on_each_escalation(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('transient_log_throttle:linnworks:escalation', 0)
            ->once()
            ->andReturn(1);

        $this->mockStore->shouldReceive('add')
            ->with('transient_log_throttle:linnworks', true, 10 * 60)
            ->once()
            ->andReturnTrue();

        $this->mockStore->shouldReceive('put')
            ->with('transient_log_throttle:linnworks:escalation', 2, 60 * 60)
            ->once();

        $this->mockLogger->shouldReceive('error')
            ->with('Linnworks API failed', Mockery::on(
                static fn(array $ctx): bool => $ctx['note'] === 'Subsequent transient failures suppressed for 10 minutes',
            ))
            ->once();

        $this->throttle->logTransient('linnworks', 'Linnworks API failed', ['status' => 500]);
    }

    #[Test]
    public function window_caps_at_thirty_minutes(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('transient_log_throttle:mixpanel:escalation', 0)
            ->once()
            ->andReturn(3);

        $this->mockStore->shouldReceive('add')
            ->with('transient_log_throttle:mixpanel', true, 30 * 60)
            ->once()
            ->andReturnTrue();

        $this->mockStore->shouldReceive('put')
            ->with('transient_log_throttle:mixpanel:escalation', 4, 60 * 60)
            ->once();

        $this->mockLogger->shouldReceive('error')
            ->with('Mixpanel API failed', Mockery::on(
                static fn(array $ctx): bool => $ctx['note'] === 'Subsequent transient failures suppressed for 30 minutes',
            ))
            ->once();

        $this->throttle->logTransient('mixpanel', 'Mixpanel API failed', ['status' => 500]);
    }

    #[Test]
    public function cap_remains_at_thirty_for_higher_escalation_counts(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('transient_log_throttle:helpscout:escalation', 0)
            ->once()
            ->andReturn(10);

        $this->mockStore->shouldReceive('add')
            ->with('transient_log_throttle:helpscout', true, 30 * 60)
            ->once()
            ->andReturnTrue();

        $this->mockStore->shouldReceive('put')
            ->with('transient_log_throttle:helpscout:escalation', 11, 60 * 60)
            ->once();

        $this->mockLogger->shouldReceive('error')
            ->with('HelpScout API failed', Mockery::on(
                static fn(array $ctx): bool => $ctx['note'] === 'Subsequent transient failures suppressed for 30 minutes',
            ))
            ->once();

        $this->throttle->logTransient('helpscout', 'HelpScout API failed', ['status' => 500]);
    }

    #[Test]
    public function redis_failure_degrades_to_escalate_with_initial_window(): void
    {
        $this->mockStore->shouldReceive('get')
            ->andThrow(new RuntimeException('Redis down'));

        $this->mockLogger->shouldReceive('warning')
            ->with('TransientLogThrottle cache failure, degrading to escalate', Mockery::on(
                static fn(array $ctx): bool => $ctx['service'] === 'linnworks'
                    && $ctx['exception'] === RuntimeException::class
                    && $ctx['message'] === 'Redis down',
            ))
            ->once();

        $this->mockLogger->shouldReceive('error')
            ->with('Linnworks API failed', Mockery::on(
                static fn(array $ctx): bool => $ctx['status'] === 500
                    && $ctx['note'] === 'Subsequent transient failures suppressed for 5 minutes',
            ))
            ->once();

        $this->throttle->logTransient('linnworks', 'Linnworks API failed', ['status' => 500]);
    }

    #[Test]
    public function services_are_independent(): void
    {
        // linnworks: first failure → error log
        $this->mockStore->shouldReceive('get')
            ->with('transient_log_throttle:linnworks:escalation', 0)
            ->once()
            ->andReturn(0);

        $this->mockStore->shouldReceive('add')
            ->with('transient_log_throttle:linnworks', true, 5 * 60)
            ->once()
            ->andReturnTrue();

        $this->mockStore->shouldReceive('put')
            ->with('transient_log_throttle:linnworks:escalation', 1, 60 * 60)
            ->once();

        // shopwired: also first failure → error log (not suppressed by linnworks)
        $this->mockStore->shouldReceive('get')
            ->with('transient_log_throttle:shopwired:escalation', 0)
            ->once()
            ->andReturn(0);

        $this->mockStore->shouldReceive('add')
            ->with('transient_log_throttle:shopwired', true, 5 * 60)
            ->once()
            ->andReturnTrue();

        $this->mockStore->shouldReceive('put')
            ->with('transient_log_throttle:shopwired:escalation', 1, 60 * 60)
            ->once();

        $this->mockLogger->shouldReceive('error')
            ->with('Linnworks API failed', Mockery::type('array'))
            ->once();

        $this->mockLogger->shouldReceive('error')
            ->with('ShopWired API failed', Mockery::type('array'))
            ->once();

        $this->throttle->logTransient('linnworks', 'Linnworks API failed', ['status' => 500]);
        $this->throttle->logTransient('shopwired', 'ShopWired API failed', ['status' => 500]);
    }

    #[Test]
    public function second_escalation_window_is_twenty_minutes(): void
    {
        $this->mockStore->shouldReceive('get')
            ->with('transient_log_throttle:google-ads:escalation', 0)
            ->once()
            ->andReturn(2);

        $this->mockStore->shouldReceive('add')
            ->with('transient_log_throttle:google-ads', true, 20 * 60)
            ->once()
            ->andReturnTrue();

        $this->mockStore->shouldReceive('put')
            ->with('transient_log_throttle:google-ads:escalation', 3, 60 * 60)
            ->once();

        $this->mockLogger->shouldReceive('error')
            ->with('Google Ads API failed', Mockery::on(
                static fn(array $ctx): bool => $ctx['note'] === 'Subsequent transient failures suppressed for 20 minutes',
            ))
            ->once();

        $this->throttle->logTransient('google-ads', 'Google Ads API failed', ['status' => 500]);
    }
}
