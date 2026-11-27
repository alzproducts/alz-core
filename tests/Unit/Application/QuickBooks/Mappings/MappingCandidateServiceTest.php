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
use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
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
        $this->adapter->shouldReceive('candidates')->twice()->with($this->accessToken, MappingEntity::Term, Mockery::type(Deadline::class))->andReturn([$candidate]);

        $this->assertSame([$candidate], $this->service()->candidates(MappingEntity::Term, Deadline::after(new DateTimeImmutable(), 60)));
        $this->assertSame([$candidate], $this->service()->candidates(MappingEntity::Term, Deadline::after(new DateTimeImmutable(), 60)));
    }

    #[Test]
    public function a_rejected_token_is_forgotten_and_rethrown(): void
    {
        $rejection = new AuthenticationExpiredException('QuickBooks');
        $this->tokens->shouldReceive('accessToken')->once()->andReturn($this->accessToken);
        $this->tokens->shouldReceive('forgetAccessToken')->once();
        $this->adapter->shouldReceive('candidates')->once()->andThrow($rejection);

        $this->expectExceptionObject($rejection);

        $this->service()->candidates(MappingEntity::Term, Deadline::after(new DateTimeImmutable(), 60));
    }

    #[Test]
    public function a_transient_failure_keeps_the_cached_token(): void
    {
        $this->tokens->shouldReceive('accessToken')->once()->andReturn($this->accessToken);
        $this->tokens->shouldNotReceive('forgetAccessToken');
        $this->adapter->shouldReceive('candidates')->once()->andThrow(new ExternalServiceUnavailableException('QuickBooks'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->service()->candidates(MappingEntity::Term, Deadline::after(new DateTimeImmutable(), 60));
    }

    private function service(): MappingCandidateService
    {
        return new MappingCandidateService($this->tokens, $this->adapter);
    }
}
