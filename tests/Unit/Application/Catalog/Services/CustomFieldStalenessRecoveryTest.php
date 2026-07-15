<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\Services;

use App\Application\Catalog\Services\CustomFieldStalenessRecovery;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(CustomFieldStalenessRecovery::class)]
final class CustomFieldStalenessRecoveryTest extends TestCase
{
    #[Test]
    public function returns_callable_result_and_dispatches_nothing_when_no_staleness(): void
    {
        $dispatcher = Mockery::mock(ShopwiredSyncDispatcherInterface::class);
        $dispatcher->shouldNotReceive('dispatchCustomFieldsSync');

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldNotReceive('warning');

        $recovery = new CustomFieldStalenessRecovery($dispatcher, $logger);

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

        $recovery = new CustomFieldStalenessRecovery($dispatcher, $logger);

        try {
            $recovery->withRecovery(static function () use ($staleness): string {
                throw $staleness;
            });
            self::fail('Expected InvalidCustomFieldValueException to be rethrown');
        } catch (InvalidCustomFieldValueException $caught) {
            self::assertSame($staleness, $caught);
        }
    }
}
