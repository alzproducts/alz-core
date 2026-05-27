<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Conversion\CallTracking\CallConversionDispatcherInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingActionRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingNumberRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingQueryRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\CallTrackingVisitRepositoryInterface;
use App\Application\Contracts\Conversion\CallTracking\InboundCallDispatcherInterface;
use App\Application\Conversion\CallTracking\UseCases\AssignTrackingNumberUseCase;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\CallTracking\Dispatchers\QueuedInboundCallDispatcher;
use App\Infrastructure\CallTracking\Repositories\EloquentCallTrackingCallRepository;
use App\Infrastructure\CallTracking\Repositories\EloquentCallTrackingQueryRepository;
use App\Infrastructure\Conversion\CallTracking\Dispatchers\QueuedCallConversionDispatcher;
use App\Infrastructure\Conversion\CallTracking\Repositories\EloquentCallTrackingActionRepository;
use App\Infrastructure\Conversion\CallTracking\Repositories\EloquentCallTrackingNumberRepository;
use App\Infrastructure\Conversion\CallTracking\Repositories\EloquentCallTrackingVisitRepository;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Deferred provider — only loaded when one of the call-tracking repository
 * bindings is resolved (i.e. when the tracking-number endpoint is hit).
 * Keeps Octane worker boot lean.
 */
final class CallTrackingServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->registerInboundCallBindings();
        $this->registerNumberAssignmentBindings();
        $this->registerQueryBindings();
        $this->registerConversionBindings();
        $this->registerUseCaseConfig();
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            CallConversionDispatcherInterface::class,
            CallTrackingActionRepositoryInterface::class,
            CallTrackingCallRepositoryInterface::class,
            CallTrackingNumberRepositoryInterface::class,
            CallTrackingQueryRepositoryInterface::class,
            CallTrackingVisitRepositoryInterface::class,
            InboundCallDispatcherInterface::class,
            AssignTrackingNumberUseCase::class,
        ];
    }

    private function registerInboundCallBindings(): void
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

    private function registerNumberAssignmentBindings(): void
    {
        $this->app->singleton(
            CallTrackingNumberRepositoryInterface::class,
            EloquentCallTrackingNumberRepository::class,
        );

        $this->app->singleton(
            CallTrackingVisitRepositoryInterface::class,
            EloquentCallTrackingVisitRepository::class,
        );
    }

    private function registerQueryBindings(): void
    {
        $this->app->singleton(
            CallTrackingQueryRepositoryInterface::class,
            EloquentCallTrackingQueryRepository::class,
        );
    }

    private function registerConversionBindings(): void
    {
        $this->app->singleton(
            CallTrackingActionRepositoryInterface::class,
            EloquentCallTrackingActionRepository::class,
        );

        $this->app->singleton(
            CallConversionDispatcherInterface::class,
            QueuedCallConversionDispatcher::class,
        );
    }

    private function registerUseCaseConfig(): void
    {
        // Container resolves this for ANY PhoneNumberE164 param on the use case —
        // Laravel 12's `->needs('$param')` only matches primitives, not class types.
        // If a second PhoneNumberE164 dep is added here, introduce a distinct
        // wrapper type so the two slots can't silently share this default.
        $this->app->when(AssignTrackingNumberUseCase::class)
            ->needs(PhoneNumberE164::class)
            ->give(static fn(): PhoneNumberE164 => self::resolveDefaultBusinessPhoneNumber());

        $this->bindAttributionWindowHours(AssignTrackingNumberUseCase::class);
        $this->bindAttributionWindowHours(EloquentCallTrackingVisitRepository::class);
    }

    /**
     * @param  class-string  $consumer
     */
    private function bindAttributionWindowHours(string $consumer): void
    {
        $this->app->when($consumer)
            ->needs('$attributionWindowHours')
            ->give(static fn(): int => self::requireConfigInt(
                'call-tracking.attribution_window_hours',
                'CALL_TRACKING_ATTRIBUTION_WINDOW_HOURS must be numeric.',
            ));
    }

    /**
     * @throws InvalidConfigurationException If the config key is missing
     * @throws InvalidFormatException If the configured value is not E.164
     */
    private static function resolveDefaultBusinessPhoneNumber(): PhoneNumberE164
    {
        return PhoneNumberE164::from(self::requireConfigString(
            'call-tracking.default_business_phone_number',
            'DEFAULT_BUSINESS_PHONE_NUMBER must be set to an E.164 phone-number string.',
        ));
    }

    /** @throws InvalidConfigurationException */
    private static function requireConfigString(string $key, string $message): string
    {
        $value = \config($key);

        if (! \is_string($value) || $value === '') {
            throw new InvalidConfigurationException($key, $message);
        }

        return $value;
    }

    /** @throws InvalidConfigurationException */
    private static function requireConfigInt(string $key, string $message): int
    {
        $value = \config($key);

        if (! \is_numeric($value)) {
            throw new InvalidConfigurationException($key, $message);
        }

        return (int) $value;
    }
}
