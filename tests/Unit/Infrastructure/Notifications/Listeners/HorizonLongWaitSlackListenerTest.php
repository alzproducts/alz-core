<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Notifications\DTOs\AlertNotificationDataDTO;
use App\Application\Notifications\Enums\AlertAudience;
use App\Infrastructure\Notifications\Listeners\HorizonLongWaitSlackListener;
use App\Infrastructure\Queue\HorizonQueueWaitInputsReader;
use App\Infrastructure\Queue\QueueWaitInputs;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Events\LongWaitDetected;
use Laravel\Horizon\RedisQueue;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(HorizonLongWaitSlackListener::class)]
#[CoversClass(HorizonQueueWaitInputsReader::class)]
#[CoversClass(QueueWaitInputs::class)]
final class HorizonLongWaitSlackListenerTest extends TestCase
{
    private ChatNotificationInterface&MockInterface $chat;

    private QueueFactory&MockInterface $queues;

    private MetricsRepository&MockInterface $metrics;

    private SupervisorRepository&MockInterface $supervisors;

    private HorizonLongWaitSlackListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('horizon.waits', ['redis-long:low' => 120]);

        $this->chat = Mockery::mock(ChatNotificationInterface::class);
        $this->queues = Mockery::mock(QueueFactory::class);
        $this->metrics = Mockery::mock(MetricsRepository::class);
        $this->supervisors = Mockery::mock(SupervisorRepository::class);

        $this->listener = new HorizonLongWaitSlackListener(
            $this->chat,
            new HorizonQueueWaitInputsReader($this->queues, $this->metrics, $this->supervisors),
        );
    }

    #[Test]
    public function alert_carries_estimate_threshold_and_metric_inputs(): void
    {
        $redis = Mockery::mock(RedisQueue::class);
        $redis->shouldReceive('readyNow')->with('low')->andReturn(40);
        $this->queues->shouldReceive('connection')->with('redis-long')->andReturn($redis);
        $this->metrics->shouldReceive('runtimeForQueue')->with('low')->andReturn(2500.0);
        $this->supervisors->shouldReceive('all')->andReturn([
            (object) ['processes' => ['redis-long:low' => 1, 'redis:high' => 9]],
            (object) ['processes' => ['redis-long:low' => 1]],
        ]);

        Log::shouldReceive('warning')->once()->with('Queue long wait detected', [
            'connection' => 'redis-long',
            'queue' => 'low',
            'estimated_wait_seconds' => 50,
            'threshold_seconds' => 120,
            'ready_jobs' => 40,
            'mean_runtime_ms' => 2500.0,
            'worker_processes' => 2,
        ]);

        $this->chat->shouldReceive('sendAlert')->once()->withArgs(
            function (AlertAudience $audience, AlertNotificationDataDTO $data): bool {
                $this->assertSame(AlertAudience::Admin, $audience);
                $this->assertStringContainsString('estimated wait 50s against a 120s threshold', $data->message);
                $this->assertSame(50, $data->context['estimated_wait_seconds']);
                $this->assertSame(120, $data->context['threshold_seconds']);
                $this->assertSame(40, $data->context['ready_jobs']);
                $this->assertSame(2500.0, $data->context['mean_runtime_ms']);
                $this->assertSame(2, $data->context['worker_processes']);

                return true;
            },
        );

        $this->listener->handle(new LongWaitDetected('redis-long', 'low', 50));
    }

    #[Test]
    public function metrics_failure_still_sends_the_alert_without_metrics(): void
    {
        $this->queues->shouldReceive('connection')->andThrow(new RuntimeException('redis down'));

        $this->chat->shouldReceive('sendAlert')->once()->withArgs(
            function (AlertAudience $audience, AlertNotificationDataDTO $data): bool {
                $this->assertSame(50, $data->context['estimated_wait_seconds']);
                $this->assertArrayNotHasKey('ready_jobs', $data->context);

                return true;
            },
        );

        $this->listener->handle(new LongWaitDetected('redis-long', 'low', 50));
    }

    #[Test]
    public function unconfigured_queue_falls_back_to_horizon_default_threshold(): void
    {
        $this->queues->shouldReceive('connection')->andThrow(new RuntimeException('x'));

        $this->chat->shouldReceive('sendAlert')->once()->withArgs(
            function (AlertAudience $audience, AlertNotificationDataDTO $data): bool {
                $this->assertSame(60, $data->context['threshold_seconds']);

                return true;
            },
        );

        $this->listener->handle(new LongWaitDetected('redis', 'other', 90));
    }
}
