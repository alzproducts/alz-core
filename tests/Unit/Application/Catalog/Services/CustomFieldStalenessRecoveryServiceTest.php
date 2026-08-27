<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\Services;

use App\Application\Catalog\Services\CustomFieldStalenessRecoveryService;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(CustomFieldStalenessRecoveryService::class)]
final class CustomFieldStalenessRecoveryServiceTest extends TestCase
{
    #[Test]
    public function returns_callable_result_and_dispatches_nothing_when_no_staleness(): void
    {
        $dispatcher = Mockery::mock(ShopwiredSyncDispatcherInterface::class);
        $dispatcher->shouldNotReceive('dispatchCustomFieldsSync');

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('warning');

        $recovery = new CustomFieldStalenessRecoveryService($dispatcher, $logger);

        $result = $recovery->withRecovery(static fn(): string => 'fields');

        self::assertSame('fields', $result);
    }

    #[Test]
    public function dispatches_definitions_resync_and_rethrows_same_exception_on_staleness(): void
    {
        $staleness = new InvalidCustomFieldValueException('colour', CustomFieldType::Text, 'array', ['unexpected']);

        $dispatcher = Mockery::mock(ShopwiredSyncDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatchCustomFieldsSync')->once();

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('Custom field staleness detected — dispatching definitions resync', $staleness->context());

        $recovery = new CustomFieldStalenessRecoveryService($dispatcher, $logger);

        try {
            $recovery->withRecovery(static function () use ($staleness): string {
                throw $staleness;
            });
            self::fail('Expected InvalidCustomFieldValueException to be rethrown');
        } catch (InvalidCustomFieldValueException $caught) {
            self::assertSame($staleness, $caught);
        }
    }

    #[Test]
    public function rethrows_staleness_exception_even_when_dispatch_fails(): void
    {
        $staleness = new InvalidCustomFieldValueException('colour', CustomFieldType::Text, 'array', ['unexpected']);

        $dispatcher = Mockery::mock(ShopwiredSyncDispatcherInterface::class);
        $dispatcher->shouldReceive('dispatchCustomFieldsSync')
            ->once()
            ->andThrow(new RuntimeException('queue unreachable'));

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once();
        $logger->shouldReceive('error')
            ->once()
            ->with('Failed to dispatch custom field staleness resync', ['dispatch_error' => 'queue unreachable']);

        $recovery = new CustomFieldStalenessRecoveryService($dispatcher, $logger);

        try {
            $recovery->withRecovery(static function () use ($staleness): string {
                throw $staleness;
            });
            self::fail('Expected InvalidCustomFieldValueException to surface despite dispatch failure');
        } catch (InvalidCustomFieldValueException $caught) {
            self::assertSame($staleness, $caught);
        }
    }
}
