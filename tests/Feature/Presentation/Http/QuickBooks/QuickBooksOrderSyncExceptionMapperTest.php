<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\QuickBooks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\QuickBooks\Exceptions\QuickBooksMappingsUnresolvedException;
use App\Presentation\Http\QuickBooks\QuickBooksOrderSyncExceptionMapper;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;
use Throwable;

#[CoversClass(QuickBooksOrderSyncExceptionMapper::class)]
final class QuickBooksOrderSyncExceptionMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function reconnectFailures(): iterable
    {
        yield 'QuickBooks authentication' => [new AuthenticationExpiredException('QuickBooks', 'test')];
        yield 'unreadable stored token' => [new CorruptApiKeyException()];
        yield 'blank client id' => [new InvalidConfigurationException('QUICKBOOKS_CLIENT_ID')];
        yield 'blank client secret' => [new InvalidConfigurationException('QUICKBOOKS_CLIENT_SECRET')];
        yield 'blank redirect uri' => [new InvalidConfigurationException('QUICKBOOKS_REDIRECT_URI')];
        yield 'blank realm id' => [new InvalidConfigurationException('QUICKBOOKS_REALM_ID')];
    }

    #[Test]
    #[DataProvider('reconnectFailures')]
    public function a_connection_failure_on_an_order_sync_route_is_412_without_errors(Throwable $failure): void
    {
        $response = $this->throwFrom('quickbooks.orders.probe', $failure)->assertStatus(412);

        $response->assertJsonPath('error.type', 'precondition_failed')->assertJsonMissingPath('error.errors');
    }

    #[Test]
    public function unresolved_mappings_are_412_naming_the_keys(): void
    {
        $this->throwFrom('quickbooks.orders.probe', new QuickBooksMappingsUnresolvedException(['tax.standard', 'payment.card']))
            ->assertStatus(412)
            ->assertJsonPath('error.errors.missing_mapping_keys', ['tax.standard', 'payment.card']);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function otherFailures(): iterable
    {
        yield 'another service authentication' => [new AuthenticationExpiredException('HelpScout', 'test')];
        yield 'invalid environment' => [new InvalidConfigurationException('QUICKBOOKS_ENVIRONMENT')];
        yield 'malformed floor' => [new InvalidConfigurationException('QUICKBOOKS_BACKFILL_FLOOR_DATE')];
    }

    #[Test]
    #[DataProvider('otherFailures')]
    public function other_failures_keep_the_status_of_every_other_route(Throwable $failure): void
    {
        $elsewhere = $this->throwFrom('other.probe', $failure)->getStatusCode();

        self::assertNotSame(412, $elsewhere);
        self::assertSame($elsewhere, $this->throwFrom('quickbooks.orders.probe', $failure)->getStatusCode());
    }

    #[Test]
    #[DataProvider('failuresMappedOnlyHere')]
    public function other_routes_are_left_alone(Throwable $failure): void
    {
        self::assertNotSame(412, $this->throwFrom('other.probe', $failure)->getStatusCode());
    }

    /**
     * An unreadable stored token is left out: InternalApiExceptionMapper answers it 412 on every route.
     *
     * @return iterable<string, array{Throwable}>
     */
    public static function failuresMappedOnlyHere(): iterable
    {
        yield 'QuickBooks authentication' => [new AuthenticationExpiredException('QuickBooks', 'test')];
        yield 'blank client id' => [new InvalidConfigurationException('QUICKBOOKS_CLIENT_ID')];
        yield 'unresolved mappings' => [new QuickBooksMappingsUnresolvedException(['tax.standard'])];
    }

    /**
     * @return TestResponse<Response>
     */
    private function throwFrom(string $routeName, Throwable $failure): TestResponse
    {
        $path = 'api/_probe/' . $routeName;
        Route::get($path, static fn() => throw $failure)->name($routeName);

        return $this->getJson('/' . $path);
    }
}
