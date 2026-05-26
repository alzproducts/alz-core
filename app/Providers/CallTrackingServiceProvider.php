<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\InboundCallDispatcherInterface;
use App\Infrastructure\CallTracking\Dispatchers\QueuedInboundCallDispatcher;
use App\Infrastructure\CallTracking\Repositories\EloquentCallTrackingCallRepository;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

final class CallTrackingServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            CallTrackingCallRepositoryInterface::class,
            EloquentCallTrackingCallRepository::class,
        );

        $this->app->singleton(
            InboundCallDispatcherInterface::class,
            QueuedInboundCallDispatcher::class,
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            CallTrackingCallRepositoryInterface::class,
            InboundCallDispatcherInterface::class,
        ];
    }
}
