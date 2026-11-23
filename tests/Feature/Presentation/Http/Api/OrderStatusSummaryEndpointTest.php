<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api;

use App\Application\Contracts\Shopwired\OrderStatusSummaryQueryRepositoryInterface;
use App\Application\Shopwired\DTOs\OrderStatusSummaryDTO;
use App\Application\Shopwired\UseCases\GetOrderStatusSummaryUseCase;
use App\Presentation\Http\Api\Controllers\OrderStatusSummaryController;
use App\Presentation\Http\Api\Resources\OrderStatusSummaryResource;
use App\Presentation\Http\Middleware\AuthenticateServiceCallerMiddleware;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(AuthenticateServiceCallerMiddleware::class)]
#[CoversClass(OrderStatusSummaryController::class)]
#[CoversClass(OrderStatusSummaryResource::class)]
#[CoversClass(GetOrderStatusSummaryUseCase::class)]
final class OrderStatusSummaryEndpointTest extends TestCase
{
    private const string PATH = '/api/orders/status-summary';

    private const string BEARER = 'fake-alz-connect-bearer';

    private const string OTHER_BEARER = 'fake-second-caller-bearer';

    private const string CLIENT_IP = '203.0.113.10';

    private const string OTHER_IP = '198.51.100.20';

    private OrderStatusSummaryQueryRepositoryInterface&MockInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->configureCaller('alz_connect', self::BEARER, self::CLIENT_IP);

        $this->repository = Mockery::mock(OrderStatusSummaryQueryRepositoryInterface::class);
        $this->app->instance(OrderStatusSummaryQueryRepositoryInterface::class, $this->repository);
    }

    #[Test]
    public function it_returns_the_contract_shape_for_a_valid_token_and_ip(): void
    {
        $this->repository->expects('getSummary')->andReturn(new OrderStatusSummaryDTO(3, ['12345', '12346'], 0));

        $response = $this->request('Bearer ' . self::BEARER);

        $response->assertOk();
        self::assertSame(
            '{"data":{"pending_paid_count":3,"unpaid_order_references":["12345","12346"],"errored_paid_count":0}}',
            $response->getContent(),
        );
    }

    #[Test]
    public function it_returns_zero_counts_and_an_empty_list_with_no_matching_orders(): void
    {
        $this->repository->expects('getSummary')->andReturn(new OrderStatusSummaryDTO(0, [], 0));

        self::assertSame(
            '{"data":{"pending_paid_count":0,"unpaid_order_references":[],"errored_paid_count":0}}',
            $this->request('Bearer ' . self::BEARER)->assertOk()->getContent(),
        );
    }

    #[Test]
    #[DataProvider('rejectedAuthorizationHeaders')]
    public function it_returns_one_identical_401_body_for_every_token_failure(?string $authorization): void
    {
        $this->repository->shouldNotReceive('getSummary');

        $response = $this->request($authorization);

        $response->assertUnauthorized();
        self::assertSame('{"error":{"type":"unauthorized","message":"Unauthorized."}}', $response->getContent());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function rejectedAuthorizationHeaders(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'no scheme' => [self::BEARER],
            'other scheme' => ['Basic ' . self::BEARER],
            'scheme only' => ['Bearer'],
            'text after the token' => ['Bearer ' . self::BEARER . ' extra'],
            'wrong' => ['Bearer wrong'],
            'the stored hash itself' => ['Bearer ' . \hash('sha256', self::BEARER)],
        ];
    }

    #[Test]
    public function it_returns_403_for_a_valid_token_from_another_ip(): void
    {
        $this->repository->shouldNotReceive('getSummary');

        $this->request('Bearer ' . self::BEARER, self::OTHER_IP)
            ->assertForbidden()
            ->assertJsonPath('error.type', 'forbidden');
    }

    #[Test]
    public function it_returns_401_not_403_for_a_wrong_token_from_another_ip(): void
    {
        $this->request('Bearer wrong', self::OTHER_IP)->assertUnauthorized();
    }

    #[Test]
    #[DataProvider('blankConfigurations')]
    public function it_refuses_without_a_query_when_configuration_is_blank(string $key, ?string $value, int $status): void
    {
        \config(["service-callers.alz_connect.{$key}" => $value]);
        $this->repository->shouldNotReceive('getSummary');

        $this->request('Bearer ' . self::BEARER)->assertStatus($status);
        $this->request('Bearer ' . self::BEARER, self::OTHER_IP)->assertStatus($status);
        $this->request('Bearer wrong')->assertUnauthorized();
        $this->request('Bearer ')->assertUnauthorized();
        $this->request(null)->assertUnauthorized();
    }

    /**
     * @return array<string, array{string, string|null, int}>
     */
    public static function blankConfigurations(): array
    {
        return [
            'hash unset' => ['token_sha256', null, 401],
            'hash empty' => ['token_sha256', '', 401],
            'hash spaces' => ['token_sha256', '  ', 401],
            'allowlist unset' => ['allowed_ips', null, 403],
            'allowlist empty' => ['allowed_ips', '', 403],
            'allowlist separators only' => ['allowed_ips', ' , ', 403],
        ];
    }

    #[Test]
    #[DataProvider('allowlistCases')]
    public function it_matches_the_client_ip_as_an_address(string $allowedIps, string $clientIp, int $status): void
    {
        \config(['service-callers.alz_connect.allowed_ips' => $allowedIps]);
        $this->repository->allows('getSummary')->andReturn(new OrderStatusSummaryDTO(0, [], 0));

        $this->request('Bearer ' . self::BEARER, $clientIp)->assertStatus($status);
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function allowlistCases(): array
    {
        return [
            'second entry, surrounding spaces' => ['198.51.100.20 ,  203.0.113.10 ', '203.0.113.10', 200],
            'two text forms of one IPv6 address' => ['2001:0DB8:0000:0000:0000:0000:0000:0001', '2001:db8::1', 200],
            'different IPv6 address' => ['2001:db8::1', '2001:db8::2', 403],
            'prefix of an entry' => ['203.0.113.100', '203.0.113.10', 403],
            'inside an IPv4 range' => ['198.51.100.20, 203.0.113.0/24', '203.0.113.10', 200],
            'outside an IPv4 range' => ['203.0.113.0/28', '203.0.113.16', 403],
            'inside an IPv6 range' => ['2001:db8::/32', '2001:db8:1::1', 200],
            'outside an IPv6 range' => ['2001:db8::/48', '2001:db8:1::1', 403],
            'invalid entry beside a valid one' => ['not-an-ip,203.0.113.10', '203.0.113.10', 200],
        ];
    }

    #[Test]
    public function it_refuses_a_second_callers_valid_token_from_that_callers_ip(): void
    {
        $this->configureCaller('second_caller', self::OTHER_BEARER, self::OTHER_IP);
        $this->repository->shouldNotReceive('getSummary');

        $this->request('Bearer ' . self::OTHER_BEARER, self::OTHER_IP)->assertUnauthorized();
    }

    #[Test]
    public function it_limits_one_ip_to_60_tokenless_requests_a_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->request(null)->assertUnauthorized();
        }

        $this->request(null)->assertTooManyRequests()->assertJsonStructure(['error' => ['type', 'message']]);
        $this->request('Bearer ' . self::BEARER)->assertTooManyRequests();
        $this->request(null, self::OTHER_IP)->assertUnauthorized();
    }

    #[Test]
    public function it_limits_valid_requests(): void
    {
        $this->repository->allows('getSummary')->andReturn(new OrderStatusSummaryDTO(0, [], 0));

        for ($i = 0; $i < 60; $i++) {
            $this->request('Bearer ' . self::BEARER)->assertOk();
        }

        $this->request('Bearer ' . self::BEARER)->assertTooManyRequests();
    }

    private function configureCaller(string $caller, string $bearer, string $allowedIps): void
    {
        \config(["service-callers.{$caller}" => [
            'token_sha256' => \hash('sha256', $bearer),
            'allowed_ips' => $allowedIps,
        ]]);
    }

    private function request(?string $authorization, string $clientIp = self::CLIENT_IP): TestResponse
    {
        $server = ['REMOTE_ADDR' => $clientIp, 'HTTP_ACCEPT' => 'application/json'];

        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        return $this->call('GET', self::PATH, [], [], [], $server);
    }
}
