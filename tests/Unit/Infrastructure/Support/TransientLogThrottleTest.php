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
        $this->mockLogger->shouldReceive('warning')->byDefault();
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
    public function first_failure_returns_initial_window_minutes(): void
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

        $result = $this->throttle->check('linnworks');

        $this->assertSame(5, $result);
    }

    #[Test]
    public function subsequent_failure_within_window_returns_null(): void
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

        $result = $this->throttle->check('shopwired');

        $this->assertNull($result);
    }

    #[Test]
    public function exponential_backoff_doubles_window_on_each_escalation(): void
    {
        // escalation count = 1 → window = 5 * 2^1 = 10
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

        $result = $this->throttle->check('linnworks');

        $this->assertSame(10, $result);
    }

    #[Test]
    public function window_caps_at_thirty_minutes(): void
    {
        // escalation count = 3 → window = 5 * 2^3 = 40, capped to 30
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

        $result = $this->throttle->check('mixpanel');

        $this->assertSame(30, $result);
    }

    #[Test]
    public function cap_remains_at_thirty_for_higher_escalation_counts(): void
    {
        // escalation count = 10 → window = 5 * 2^10 = 5120, capped to 30
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

        $result = $this->throttle->check('helpscout');

        $this->assertSame(30, $result);
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

        $result = $this->throttle->check('linnworks');

        $this->assertSame(5, $result);
    }

    #[Test]
    public function services_are_independent(): void
    {
        // linnworks: first failure → escalate
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

        // shopwired: also first failure → escalate (not suppressed by linnworks)
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

        $linnworksResult = $this->throttle->check('linnworks');
        $shopwiredResult = $this->throttle->check('shopwired');

        $this->assertSame(5, $linnworksResult);
        $this->assertSame(5, $shopwiredResult);
    }

    #[Test]
    public function second_escalation_window_is_ten_minutes(): void
    {
        // escalation count = 2 → window = 5 * 2^2 = 20
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

        $result = $this->throttle->check('google-ads');

        $this->assertSame(20, $result);
    }
}
