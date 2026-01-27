<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\ProductIdentifierResolverInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Shopwired\UseCases\SetProductFreeDeliveryUseCase;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use App\Domain\Catalog\Product\Exceptions\ProductIdentifierResolutionException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * SetProductFreeDeliveryUseCase Unit Tests.
 *
 * Tests batch processing orchestration:
 * - Empty input handling
 * - Continue-on-failure semantics
 * - Permanent vs temporary failure classification
 */
#[CoversClass(SetProductFreeDeliveryUseCase::class)]
final class SetProductFreeDeliveryUseCaseTest extends TestCase
{
    private ProductIdentifierResolverInterface&MockInterface $resolver;

    private ProductUpdateClientInterface&MockInterface $updateClient;

    private LoggerInterface&MockInterface $logger;

    private SetProductFreeDeliveryUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = Mockery::mock(ProductIdentifierResolverInterface::class);
        $this->updateClient = Mockery::mock(ProductUpdateClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info', 'debug', 'warning')->byDefault();

        $this->useCase = new SetProductFreeDeliveryUseCase(
            $this->resolver,
            $this->updateClient,
            $this->logger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Empty Input Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_empty_result_for_empty_commands(): void
    {
        $result = $this->useCase->execute([]);

        $this->assertSame(0, $result->total);
        $this->assertSame(0, $result->succeeded);
        $this->assertSame([], $result->permanentFailures);
        $this->assertSame([], $result->temporaryFailures);
    }

    /*
    |--------------------------------------------------------------------------
    | Success Branch
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_processes_single_command_successfully(): void
    {
        $command = new SetFreeDeliveryCommand('SKU-123', FreeDeliveryType::Standard);

        $this->resolver
            ->shouldReceive('resolveToParentProductId')
            ->once()
            ->with('SKU-123')
            ->andReturn(12345);

        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->with(12345, ['free_delivery' => 'Standard']);

        $result = $this->useCase->execute([$command]);

        $this->assertSame(1, $result->total);
        $this->assertSame(1, $result->succeeded);
        $this->assertTrue($result->allSucceeded());
    }

    #[Test]
    public function execute_sends_empty_string_for_none_type(): void
    {
        $command = new SetFreeDeliveryCommand(99999, FreeDeliveryType::None);

        $this->resolver
            ->shouldReceive('resolveToParentProductId')
            ->once()
            ->with(99999)
            ->andReturn(99999);

        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->with(99999, ['free_delivery' => '']);

        $result = $this->useCase->execute([$command]);

        $this->assertSame(1, $result->succeeded);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failure Classification
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_classifies_sku_not_found_as_permanent(): void
    {
        $command = new SetFreeDeliveryCommand('BAD-SKU', FreeDeliveryType::Standard);

        $this->resolver
            ->shouldReceive('resolveToParentProductId')
            ->once()
            ->andThrow(ProductIdentifierResolutionException::skuNotFound('BAD-SKU'));

        $result = $this->useCase->execute([$command]);

        $this->assertSame(1, $result->total);
        $this->assertSame(0, $result->succeeded);
        $this->assertCount(1, $result->permanentFailures);
        $this->assertSame('BAD-SKU', $result->permanentFailures[0]['identifier']);
        $this->assertFalse($result->hasRetryableFailures());
    }

    #[Test]
    public function execute_classifies_resource_not_found_as_permanent(): void
    {
        $command = new SetFreeDeliveryCommand(12345, FreeDeliveryType::Express);

        $this->resolver
            ->shouldReceive('resolveToParentProductId')
            ->once()
            ->andReturn(12345);

        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->andThrow(new ResourceNotFoundException('ShopWired', 'Product', 12345));

        $result = $this->useCase->execute([$command]);

        $this->assertSame(0, $result->succeeded);
        $this->assertCount(1, $result->permanentFailures);
        $this->assertSame(12345, $result->permanentFailures[0]['identifier']);
    }

    /*
    |--------------------------------------------------------------------------
    | Temporary Failure Classification
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_classifies_service_unavailable_as_temporary(): void
    {
        $command = new SetFreeDeliveryCommand('SKU-123', FreeDeliveryType::Standard);

        $this->resolver
            ->shouldReceive('resolveToParentProductId')
            ->once()
            ->andReturn(12345);

        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException('ShopWired', 60));

        $result = $this->useCase->execute([$command]);

        $this->assertSame(0, $result->succeeded);
        $this->assertCount(1, $result->temporaryFailures);
        $this->assertTrue($result->hasRetryableFailures());
        $this->assertSame(['SKU-123'], $result->getRetryableIdentifiers());
    }

    #[Test]
    public function execute_classifies_database_failure_as_temporary(): void
    {
        $command = new SetFreeDeliveryCommand('SKU-456', FreeDeliveryType::Express);

        $this->resolver
            ->shouldReceive('resolveToParentProductId')
            ->once()
            ->andThrow(new DatabaseOperationFailedException('query', 'Connection lost'));

        $result = $this->useCase->execute([$command]);

        $this->assertCount(1, $result->temporaryFailures);
        $this->assertTrue($result->hasRetryableFailures());
    }

    /*
    |--------------------------------------------------------------------------
    | Continue-on-Failure Semantics
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_continues_after_failure_and_processes_remaining(): void
    {
        $commands = [
            new SetFreeDeliveryCommand('FAIL-SKU', FreeDeliveryType::Standard),
            new SetFreeDeliveryCommand('OK-SKU', FreeDeliveryType::Express),
            new SetFreeDeliveryCommand('TEMP-FAIL', FreeDeliveryType::None),
        ];

        // First command: permanent failure
        $this->resolver
            ->shouldReceive('resolveToParentProductId')
            ->with('FAIL-SKU')
            ->andThrow(ProductIdentifierResolutionException::skuNotFound('FAIL-SKU'));

        // Second command: success
        $this->resolver
            ->shouldReceive('resolveToParentProductId')
            ->with('OK-SKU')
            ->andReturn(111);

        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->with(111, ['free_delivery' => 'Express'])
            ->once();

        // Third command: temporary failure
        $this->resolver
            ->shouldReceive('resolveToParentProductId')
            ->with('TEMP-FAIL')
            ->andReturn(222);

        $this->updateClient
            ->shouldReceive('updateCustomFields')
            ->with(222, ['free_delivery' => ''])
            ->andThrow(new ExternalServiceUnavailableException('ShopWired'));

        $result = $this->useCase->execute($commands);

        $this->assertSame(3, $result->total);
        $this->assertSame(1, $result->succeeded);
        $this->assertCount(1, $result->permanentFailures);
        $this->assertCount(1, $result->temporaryFailures);
        $this->assertTrue($result->isPartialSuccess());
    }
}
