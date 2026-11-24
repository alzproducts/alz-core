<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncRecoveryService;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Unit\Application\QuickBooks\OrderSync\SyncOrderFixture;

#[CoversClass(OrderSyncRecoveryService::class)]
final class OrderSyncRecoveryServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&OrderSyncRepositoryInterface $orderSyncs;

    private MockInterface&QuickBooksTokenService $tokens;

    private MockInterface&LoggerInterface $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->orderSyncs = Mockery::mock(OrderSyncRepositoryInterface::class);
        $this->tokens = Mockery::mock(QuickBooksTokenService::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
    }

    #[Test]
    public function a_rejected_token_is_dropped_after_every_release(): void
    {
        $this->orderSyncs->shouldReceive('release')->once()->globally()->ordered()->with('sync-1', 'token-1')->andReturn(true);
        $this->orderSyncs->shouldReceive('release')->once()->globally()->ordered()->with('sync-2', 'token-2')->andReturn(false);
        $this->tokens->shouldReceive('forgetAccessToken')->once()->globally()->ordered();

        $this->service()->recover([self::claimed(1), self::claimed(2)], new AuthenticationExpiredException('QuickBooks'));
    }

    #[Test]
    public function any_other_cause_keeps_the_token(): void
    {
        $this->orderSyncs->shouldReceive('release')->once()->andReturn(true);
        $this->tokens->shouldNotReceive('forgetAccessToken');

        $this->service()->recover([self::claimed(1)], new RuntimeException('cause'));
    }

    #[Test]
    public function nothing_thrown_here_escapes_and_only_its_class_is_logged(): void
    {
        $this->orderSyncs->shouldReceive('release')->with('sync-1', 'token-1')->once()->andThrow(new RuntimeException('customer data'));
        $this->orderSyncs->shouldReceive('release')->with('sync-2', 'token-2')->once()->andReturn(true);
        $this->tokens->shouldReceive('forgetAccessToken')->once()->andThrow(new RuntimeException('customer data'));
        $this->logger->shouldReceive('error')->once()
            ->with('QuickBooks order sync row could not be released', ['reference' => 1, 'exception' => RuntimeException::class]);
        $this->logger->shouldReceive('error')->once()
            ->with('Rejected QuickBooks access token could not be dropped', ['exception' => RuntimeException::class]);

        $this->service()->recover([self::claimed(1), self::claimed(2)], new AuthenticationExpiredException('QuickBooks'));
    }

    private function service(): OrderSyncRecoveryService
    {
        return new OrderSyncRecoveryService($this->orderSyncs, $this->tokens, $this->logger);
    }

    private static function claimed(int $reference): ClaimedOrderDTO
    {
        return new ClaimedOrderDTO(
            new SourceOrderRowDTO(
                reference: $reference,
                externalId: $reference,
                createdAt: new DateTimeImmutable('2026-11-01 10:00:00+00'),
                status: OrderLifecycleStatus::Processing,
                paymentMethod: 'card',
                billing: SyncOrderFixture::contact(),
                billingEmail: 'buyer@example.test',
                billingPhone: '',
                delivery: SyncOrderFixture::contact(),
                shippingMethod: 'Next Day',
                shippingTotalNet: '0',
                shippingVatRate: null,
                comments: '',
                transactionId: '',
                lines: [],
            ),
            new OrderSyncClaimDTO("sync-{$reference}", "token-{$reference}", 1, new DateTimeImmutable('2026-11-01 10:05:00+00'), null),
        );
    }
}
