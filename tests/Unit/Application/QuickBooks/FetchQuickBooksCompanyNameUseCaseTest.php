<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksClientInterface;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Application\QuickBooks\UseCases\FetchQuickBooksCompanyNameUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(FetchQuickBooksCompanyNameUseCase::class)]
final class FetchQuickBooksCompanyNameUseCaseTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&QuickBooksTokenService $tokens;

    private MockInterface&QuickBooksClientInterface $client;

    private ApiKeyToken $accessToken;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = Mockery::mock(QuickBooksTokenService::class);
        $this->client = Mockery::mock(QuickBooksClientInterface::class);
        $this->accessToken = new ApiKeyToken('access');
    }

    #[Test]
    public function it_returns_the_company_name_using_the_current_access_token(): void
    {
        $this->tokens->shouldReceive('accessToken')->once()->andReturn($this->accessToken);
        $this->client->shouldReceive('companyName')->once()->with($this->accessToken)->andReturn('Sandbox Company_US_1');
        $this->tokens->shouldNotReceive('forgetAccessToken');

        $this->assertSame('Sandbox Company_US_1', $this->useCase()->execute());
    }

    #[Test]
    public function a_rejected_access_token_is_forgotten_and_rethrown(): void
    {
        $rejection = new AuthenticationExpiredException('QuickBooks');
        $this->tokens->shouldReceive('accessToken')->once()->andReturn($this->accessToken);
        $this->client->shouldReceive('companyName')->once()->andThrow($rejection);
        $this->tokens->shouldReceive('forgetAccessToken')->once();

        try {
            $this->useCase()->execute();
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            $this->assertSame($rejection, $e);
        }
    }

    #[Test]
    public function a_transient_failure_keeps_the_cached_token(): void
    {
        $this->tokens->shouldReceive('accessToken')->once()->andReturn($this->accessToken);
        $this->client->shouldReceive('companyName')->once()->andThrow(new ExternalServiceUnavailableException('QuickBooks'));
        $this->tokens->shouldNotReceive('forgetAccessToken');

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->useCase()->execute();
    }

    #[Test]
    public function a_failed_refresh_never_reaches_the_api(): void
    {
        $this->tokens->shouldReceive('accessToken')->once()->andThrow(new AuthenticationExpiredException('QuickBooks'));
        $this->client->shouldNotReceive('companyName');
        $this->tokens->shouldNotReceive('forgetAccessToken');

        $this->expectException(AuthenticationExpiredException::class);

        $this->useCase()->execute();
    }

    private function useCase(): FetchQuickBooksCompanyNameUseCase
    {
        return new FetchQuickBooksCompanyNameUseCase($this->tokens, $this->client, new NullLogger());
    }
}
