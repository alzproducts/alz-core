<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Infrastructure\QuickBooks\QuickBooksClient;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Data\IPPCompanyInfo;
use stdClass;

#[CoversClass(QuickBooksClient::class)]
final class QuickBooksClientTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    public function it_returns_the_company_name(): void
    {
        $this->assertSame('Sandbox Company_US_1', $this->clientReturning(self::companyInfo('Sandbox Company_US_1'))->companyName(new ApiKeyToken('access')));
    }

    #[Test]
    #[DataProvider('unusableResults')]
    public function an_unusable_result_raises_unexpected_api_result(mixed $result, string $reason): void
    {
        try {
            $this->clientReturning($result)->companyName(new ApiKeyToken('access'));
            $this->fail('Expected UnexpectedApiResultException');
        } catch (UnexpectedApiResultException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unusableResults(): array
    {
        return [
            'null result' => [null, 'CompanyInfo missing from response'],
            'wrong type' => [new stdClass(), 'CompanyInfo missing from response'],
            'no name' => [self::companyInfo(null), 'CompanyName blank'],
            'blank name' => [self::companyInfo('   '), 'CompanyName blank'],
        ];
    }

    #[Test]
    public function a_transport_failure_propagates(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('companyInfo')->once()->andThrow(new AuthenticationExpiredException('QuickBooks'));

        $this->expectException(AuthenticationExpiredException::class);

        (new QuickBooksClient($transport, new NullLogger()))->companyName(new ApiKeyToken('access'));
    }

    private static function companyInfo(?string $name): IPPCompanyInfo
    {
        $companyInfo = new IPPCompanyInfo();
        $companyInfo->CompanyName = $name;

        return $companyInfo;
    }

    private function clientReturning(mixed $result): QuickBooksClient
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('companyInfo')->once()->andReturn($result);

        return new QuickBooksClient($transport, new NullLogger());
    }
}
