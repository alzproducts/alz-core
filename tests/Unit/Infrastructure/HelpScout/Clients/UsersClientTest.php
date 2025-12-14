<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Clients;

use App\Domain\CustomerService\ValueObjects\SupportAgent;
use App\Infrastructure\HelpScout\Clients\UsersClient;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(UsersClient::class)]
final class UsersClientTest extends TestCase
{
    private HelpScoutHttpTransport&MockInterface $mockTransport;

    private UsersClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTransport = Mockery::mock(HelpScoutHttpTransport::class);
        $this->client = new UsersClient($this->mockTransport);
    }

    /*
    |--------------------------------------------------------------------------
    | list() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function list_returns_domain_support_agents(): void
    {
        $mockResponse = $this->createMockResponse([
            '_embedded' => [
                'users' => [
                    [
                        'id' => 12345,
                        'email' => 'jane@example.com',
                        'firstName' => 'Jane',
                        'lastName' => 'Doe',
                        'photoUrl' => 'https://example.com/jane.jpg',
                        'role' => 'admin',
                        'timezone' => 'Europe/London',
                    ],
                    [
                        'id' => 67890,
                        'email' => 'john@example.com',
                        'firstName' => 'John',
                        'lastName' => 'Smith',
                        'photoUrl' => null,
                        'role' => 'user',
                        'timezone' => 'America/New_York',
                    ],
                ],
            ],
        ]);

        $this->mockTransport->expects('get')
            ->with('/users')
            ->once()
            ->andReturn($mockResponse);

        $result = $this->client->list();

        $this->assertCount(2, $result);
        $this->assertInstanceOf(SupportAgent::class, $result[0]);
        $this->assertInstanceOf(SupportAgent::class, $result[1]);
        $this->assertSame(12345, $result[0]->id);
        $this->assertSame('jane@example.com', $result[0]->email);
        $this->assertSame(67890, $result[1]->id);
        $this->assertSame('john@example.com', $result[1]->email);
    }

    #[Test]
    public function list_returns_empty_array_when_no_users(): void
    {
        $mockResponse = $this->createMockResponse([
            '_embedded' => [
                'users' => [],
            ],
        ]);

        $this->mockTransport->expects('get')
            ->with('/users')
            ->once()
            ->andReturn($mockResponse);

        $result = $this->client->list();

        $this->assertSame([], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | findByEmail() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function find_by_email_returns_matching_support_agent(): void
    {
        $mockResponse = $this->createMockResponse([
            '_embedded' => [
                'users' => [
                    [
                        'id' => 11111,
                        'email' => 'alice@example.com',
                        'firstName' => 'Alice',
                        'lastName' => 'Wonder',
                        'photoUrl' => null,
                        'role' => 'user',
                        'timezone' => 'UTC',
                    ],
                    [
                        'id' => 22222,
                        'email' => 'bob@example.com',
                        'firstName' => 'Bob',
                        'lastName' => 'Builder',
                        'photoUrl' => null,
                        'role' => 'admin',
                        'timezone' => 'UTC',
                    ],
                ],
            ],
        ]);

        $this->mockTransport->expects('get')
            ->with('/users')
            ->once()
            ->andReturn($mockResponse);

        $result = $this->client->findByEmail('bob@example.com');

        $this->assertNotNull($result);
        $this->assertInstanceOf(SupportAgent::class, $result);
        $this->assertSame(22222, $result->id);
        $this->assertSame('bob@example.com', $result->email);
        $this->assertSame('Bob', $result->firstName);
    }

    #[Test]
    public function find_by_email_is_case_insensitive(): void
    {
        $mockResponse = $this->createMockResponse([
            '_embedded' => [
                'users' => [
                    [
                        'id' => 33333,
                        'email' => 'Agent@Example.Com',
                        'firstName' => 'Agent',
                        'lastName' => 'Smith',
                        'photoUrl' => null,
                        'role' => 'user',
                        'timezone' => 'UTC',
                    ],
                ],
            ],
        ]);

        $this->mockTransport->expects('get')
            ->with('/users')
            ->once()
            ->andReturn($mockResponse);

        $result = $this->client->findByEmail('agent@example.com');

        $this->assertNotNull($result);
        $this->assertSame(33333, $result->id);
    }

    #[Test]
    public function find_by_email_returns_null_when_not_found(): void
    {
        $mockResponse = $this->createMockResponse([
            '_embedded' => [
                'users' => [
                    [
                        'id' => 44444,
                        'email' => 'existing@example.com',
                        'firstName' => 'Existing',
                        'lastName' => 'User',
                        'photoUrl' => null,
                        'role' => 'user',
                        'timezone' => 'UTC',
                    ],
                ],
            ],
        ]);

        $this->mockTransport->expects('get')
            ->with('/users')
            ->once()
            ->andReturn($mockResponse);

        $result = $this->client->findByEmail('nonexistent@example.com');

        $this->assertNull($result);
    }

    #[Test]
    public function find_by_email_returns_null_when_users_empty(): void
    {
        $mockResponse = $this->createMockResponse([
            '_embedded' => [
                'users' => [],
            ],
        ]);

        $this->mockTransport->expects('get')
            ->with('/users')
            ->once()
            ->andReturn($mockResponse);

        $result = $this->client->findByEmail('any@example.com');

        $this->assertNull($result);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function createMockResponse(array $json): Response&MockInterface
    {
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->allows('json')->andReturn($json);

        return $mockResponse;
    }
}
