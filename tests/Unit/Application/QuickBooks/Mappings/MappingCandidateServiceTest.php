<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\Mappings;

use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use Closure;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MappingCandidateService::class)]
final class MappingCandidateServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&QuickBooksTokenService $tokens;

    private MockInterface&QuickBooksMappingCandidatesInterface $adapter;

    private ApiKeyToken $accessToken;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = Mockery::mock(QuickBooksTokenService::class);
        $this->adapter = Mockery::mock(QuickBooksMappingCandidatesInterface::class);
        $this->accessToken = new ApiKeyToken('access');
    }

    #[Test]
    public function it_takes_a_token_per_call(): void
    {
        $candidate = new MappingCandidateDTO('1', 'Net 30', true);
        $this->tokens->shouldReceive('accessToken')->twice()->andReturn($this->accessToken);
        $this->tokens->shouldNotReceive('forgetAccessToken');
        $this->adapter->shouldReceive('candidates')->once()->with($this->accessToken, MappingEntity::Term)->andReturn([$candidate]);
        $this->adapter->shouldReceive('create')->once()->with($this->accessToken, MappingEntity::Term, 'Net 30')->andReturn($candidate);

        $this->assertSame([$candidate], $this->service()->candidates(MappingEntity::Term));
        $this->assertSame($candidate, $this->service()->create(MappingEntity::Term, 'Net 30'));
    }

    /**
     * @return array<string, array{string, Closure(MappingCandidateService): mixed}>
     */
    public static function calls(): array
    {
        return [
            'candidates' => ['candidates', static fn(MappingCandidateService $service): array => $service->candidates(MappingEntity::Term)],
            'create' => ['create', static fn(MappingCandidateService $service): MappingCandidateDTO => $service->create(MappingEntity::Term, 'Net 30')],
        ];
    }

    /**
     * @param Closure(MappingCandidateService): mixed $call
     */
    #[Test]
    #[DataProvider('calls')]
    public function a_rejected_token_is_forgotten_and_rethrown(string $method, Closure $call): void
    {
        $rejection = new AuthenticationExpiredException('QuickBooks');
        $this->tokens->shouldReceive('accessToken')->once()->andReturn($this->accessToken);
        $this->tokens->shouldReceive('forgetAccessToken')->once();
        $this->adapter->shouldReceive($method)->once()->andThrow($rejection);

        $this->expectExceptionObject($rejection);

        $call($this->service());
    }

    /**
     * @param Closure(MappingCandidateService): mixed $call
     */
    #[Test]
    #[DataProvider('calls')]
    public function a_transient_failure_keeps_the_cached_token(string $method, Closure $call): void
    {
        $this->tokens->shouldReceive('accessToken')->once()->andReturn($this->accessToken);
        $this->tokens->shouldNotReceive('forgetAccessToken');
        $this->adapter->shouldReceive($method)->once()->andThrow(new ExternalServiceUnavailableException('QuickBooks'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $call($this->service());
    }

    private function service(): MappingCandidateService
    {
        return new MappingCandidateService($this->tokens, $this->adapter);
    }
}
