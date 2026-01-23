<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\CustomerService;

use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\Exceptions\Infrastructure\ConfigurationNotFoundException;
use App\Infrastructure\CustomerService\EscalationsConfigRepository;
use App\Infrastructure\Database\DatabaseGateway;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use JsonException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(EscalationsConfigRepository::class)]
final class EscalationsConfigRepositoryTest extends TestCase
{
    private DatabaseGateway&MockInterface $mockGateway;

    private ConnectionInterface&MockInterface $mockConnection;

    private EscalationsConfigRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockGateway = Mockery::mock(DatabaseGateway::class);
        $this->mockConnection = Mockery::mock(ConnectionInterface::class);

        // Gateway's connection() method returns the mock connection
        $this->mockGateway->allows('connection')->andReturn($this->mockConnection);

        $this->repository = new EscalationsConfigRepository($this->mockGateway);
    }

    /*
    |--------------------------------------------------------------------------
    | get() - Successful Retrieval Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_returns_escalations_config_when_found(): void
    {
        $settings = [
            'lateThresholdHours' => 24,
            'latePriorityThresholdHours' => 4,
            'priorityTags' => ['urgent', 'priority'],
            'excludedTags' => ['spam', 'on-hold'],
            'assignedTag' => 'server to-do',
        ];

        $this->mockGateway->expects('query')
            ->with(Mockery::type(Closure::class))
            ->andReturnUsing(static fn(Closure $operation): object => (object) ['settings' => \json_encode($settings)]);

        $result = $this->repository->get();

        $this->assertInstanceOf(EscalationsConfig::class, $result);
        $this->assertSame(24, $result->lateThresholdHours);
        $this->assertSame(4, $result->latePriorityThresholdHours);
        $this->assertSame(['urgent', 'priority'], $result->priorityTags);
        $this->assertSame(['spam', 'on-hold'], $result->excludedTags);
        $this->assertSame('server to-do', $result->assignedTag);
    }

    #[Test]
    public function get_returns_config_with_empty_tag_arrays(): void
    {
        $settings = [
            'lateThresholdHours' => 12,
            'latePriorityThresholdHours' => 2,
            'priorityTags' => [],
            'excludedTags' => [],
            'assignedTag' => 'handling',
        ];

        $this->mockGateway->expects('query')
            ->with(Mockery::type(Closure::class))
            ->andReturnUsing(static fn(Closure $operation): object => (object) ['settings' => \json_encode($settings)]);

        $result = $this->repository->get();

        $this->assertSame([], $result->priorityTags);
        $this->assertSame([], $result->excludedTags);
    }

    #[Test]
    public function get_passes_correct_closure_to_gateway(): void
    {
        $settings = [
            'lateThresholdHours' => 48,
            'latePriorityThresholdHours' => 8,
            'priorityTags' => ['critical'],
            'excludedTags' => [],
            'assignedTag' => 'active',
        ];

        $mockBuilder = Mockery::mock(Builder::class);
        $mockBuilder->expects('where')
            ->with('table_name', 'hs_escalations')
            ->once()
            ->andReturnSelf();
        $mockBuilder->expects('where')
            ->with('enabled', true)
            ->once()
            ->andReturnSelf();
        $mockBuilder->expects('first')
            ->once()
            ->andReturn((object) ['settings' => \json_encode($settings)]);

        $this->mockConnection->expects('table')
            ->with('config.dashboard')
            ->once()
            ->andReturn($mockBuilder);

        // Execute the actual closure passed to gateway->query()
        $this->mockGateway->expects('query')
            ->with(Mockery::type(Closure::class))
            ->andReturnUsing(static fn(Closure $operation): ?object => $operation());

        $result = $this->repository->get();

        $this->assertSame(48, $result->lateThresholdHours);
    }

    /*
    |--------------------------------------------------------------------------
    | get() - Configuration Not Found Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_throws_configuration_not_found_when_row_is_null(): void
    {
        $this->mockGateway->expects('query')
            ->with(Mockery::type(Closure::class))
            ->andReturn(null);

        $this->expectException(ConfigurationNotFoundException::class);
        $this->expectExceptionMessage("Required configuration 'hs_escalations' not found or disabled");

        $this->repository->get();
    }

    #[Test]
    public function get_throws_with_correct_config_name(): void
    {
        $this->mockGateway->expects('query')
            ->andReturn(null);

        try {
            $this->repository->get();
            $this->fail('Expected ConfigurationNotFoundException');
        } catch (ConfigurationNotFoundException $e) {
            $this->assertSame('hs_escalations', $e->configName);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | get() - JSON Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_throws_json_exception_for_invalid_json(): void
    {
        $this->mockGateway->expects('query')
            ->andReturnUsing(static fn(Closure $operation): object => (object) ['settings' => 'invalid json']);

        $this->expectException(JsonException::class);

        $this->repository->get();
    }

    #[Test]
    public function get_parses_json_with_all_required_fields(): void
    {
        $settings = [
            'lateThresholdHours' => 72,
            'latePriorityThresholdHours' => 12,
            'priorityTags' => ['vip', 'enterprise', 'urgent'],
            'excludedTags' => ['archived', 'spam', 'test'],
            'assignedTag' => 'in-progress',
        ];

        $this->mockGateway->expects('query')
            ->andReturnUsing(static fn(Closure $operation): object => (object) ['settings' => \json_encode($settings)]);

        $result = $this->repository->get();

        $this->assertSame(72, $result->lateThresholdHours);
        $this->assertSame(12, $result->latePriorityThresholdHours);
        $this->assertCount(3, $result->priorityTags);
        $this->assertContains('vip', $result->priorityTags);
        $this->assertContains('enterprise', $result->priorityTags);
        $this->assertContains('urgent', $result->priorityTags);
        $this->assertCount(3, $result->excludedTags);
        $this->assertSame('in-progress', $result->assignedTag);
    }
}
