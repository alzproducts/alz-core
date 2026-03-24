<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Operations\Listeners;

use App\Application\Operations\UseCases\RecordPricePeriodUseCase;
use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Operations\Listeners\RecordPricePeriodListener;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(RecordPricePeriodListener::class)]
final class RecordPricePeriodListenerTest extends TestCase
{
    private RecordPricePeriodUseCase&MockInterface $useCase;

    private RecordPricePeriodListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCase = Mockery::mock(RecordPricePeriodUseCase::class);
        $this->listener = new RecordPricePeriodListener($this->useCase);
    }

    #[Test]
    public function happy_path_delegates_to_use_case(): void
    {
        $event = self::createEvent();

        $this->useCase->shouldReceive('execute')
            ->once()
            ->with($event->sku, $event->newPrices);

        $this->listener->handle($event);
    }

    #[Test]
    public function transient_failure_with_retry_after_releases_to_queue(): void
    {
        $event = self::createEvent();

        $this->useCase->shouldReceive('execute')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('PostgreSQL', 120));

        // Mock the InteractsWithQueue trait methods
        $listener = Mockery::mock(RecordPricePeriodListener::class, [$this->useCase])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $listener->shouldReceive('attempts')->andReturn(1);
        $listener->shouldReceive('release')->once()->with(120);

        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Price period recording: transient database failure, will retry',
                Mockery::on(static fn(array $ctx): bool => $ctx['sku'] === 'TEST-001'
                    && $ctx['retry_after'] === 120),
            );

        $listener->handle($event);
    }

    #[Test]
    public function transient_failure_without_retry_after_throws(): void
    {
        $event = self::createEvent();

        $this->useCase->shouldReceive('execute')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('PostgreSQL'));

        $listener = Mockery::mock(RecordPricePeriodListener::class, [$this->useCase])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $listener->shouldReceive('attempts')->andReturn(1);

        Log::shouldReceive('warning')->once();

        $this->expectException(ExternalServiceUnavailableException::class);

        $listener->handle($event);
    }

    #[Test]
    public function permanent_database_failure_fails_and_throws(): void
    {
        $event = self::createEvent();
        $exception = new DatabaseOperationFailedException('insert', 'constraint violation');

        $this->useCase->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $listener = Mockery::mock(RecordPricePeriodListener::class, [$this->useCase])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $listener->shouldReceive('fail')->once()->with($exception);

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Price period recording: permanent database failure',
                Mockery::on(static fn(array $ctx): bool => $ctx['sku'] === 'TEST-001'
                    && $ctx['exception'] === DatabaseOperationFailedException::class),
            );

        $this->expectException(DatabaseOperationFailedException::class);

        $listener->handle($event);
    }

    #[Test]
    public function duplicate_record_fails_and_throws(): void
    {
        $event = self::createEvent();
        $exception = new DuplicateRecordException('operations.price_periods', 'sku_unique');

        $this->useCase->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $listener = Mockery::mock(RecordPricePeriodListener::class, [$this->useCase])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $listener->shouldReceive('fail')->once()->with($exception);

        Log::shouldReceive('error')->once();

        $this->expectException(DuplicateRecordException::class);

        $listener->handle($event);
    }

    #[Test]
    public function unexpected_error_fails_at_critical_level(): void
    {
        $event = self::createEvent();
        $exception = new RuntimeException('unexpected');

        $this->useCase->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $listener = Mockery::mock(RecordPricePeriodListener::class, [$this->useCase])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $listener->shouldReceive('fail')->once()->with($exception);

        Log::shouldReceive('critical')
            ->once()
            ->with(
                'Price period recording: unexpected error',
                Mockery::on(static fn(array $ctx): bool => $ctx['sku'] === 'TEST-001'
                    && $ctx['exception'] === RuntimeException::class),
            );

        $this->expectException(RuntimeException::class);

        $listener->handle($event);
    }

    #[Test]
    public function failed_method_logs_error(): void
    {
        $event = self::createEvent();
        $exception = new RuntimeException('all retries exhausted');

        Log::shouldReceive('error')
            ->once()
            ->with(
                'Price period recording failed permanently',
                Mockery::on(static fn(array $ctx): bool => $ctx['sku'] === 'TEST-001'),
            );

        $this->listener->failed($event, $exception);
    }

    // ========================================================================
    // Queue Configuration
    // ========================================================================

    #[Test]
    public function queue_configuration_is_correct(): void
    {
        self::assertSame(4, $this->listener->tries);
        self::assertSame([60, 300, 1200], $this->listener->backoff);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private static function createEvent(): SkuRetailPricingUpdatedEvent
    {
        return new SkuRetailPricingUpdatedEvent(
            productId: IntId::fromTrusted(1),
            sku: Sku::fromTrusted('TEST-001'),
            previousPrices: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
            ),
            newPrices: new ProductRetailPricing(
                basePrice: Money::inclusive(25.00),
            ),
        );
    }
}
