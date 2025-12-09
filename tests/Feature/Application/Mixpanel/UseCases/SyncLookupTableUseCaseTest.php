<?php

declare(strict_types=1);

namespace Tests\Feature\Application\Mixpanel\UseCases;

use App\Application\Contracts\LookupTableProviderInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Mixpanel\UseCases\SyncLookupTableUseCase;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\UnexpectedApiResultException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SyncLookupTableUseCase::class)]
final class SyncLookupTableUseCaseTest extends TestCase
{
    private LookupTableProviderInterface&MockInterface $provider;

    private MixpanelClientInterface&MockInterface $mixpanelClient;

    private LoggerInterface&MockInterface $loggerMock;

    private SyncLookupTableUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = Mockery::mock(LookupTableProviderInterface::class);
        $this->mixpanelClient = Mockery::mock(MixpanelClientInterface::class);
        $this->loggerMock = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();

        $this->useCase = new SyncLookupTableUseCase(
            $this->provider,
            $this->mixpanelClient,
            $this->loggerMock,
        );
    }

    // ========================================================================
    // Happy Path Tests
    // ========================================================================

    #[Test]
    public function it_syncs_single_row_to_mixpanel(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['col1', 'col2']);

        $this->provider
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn([['123', 'Campaign One']]);

        $this->mixpanelClient
            ->shouldReceive('replaceLookupTable')
            ->once()
            ->with('utm_campaigns', ['col1', 'col2'], [['123', 'Campaign One']]);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting lookup table sync', ['table_key' => 'utm_campaigns', 'source' => 'Google Ads'])
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Retrieved data from source', ['table_key' => 'utm_campaigns', 'source' => 'Google Ads', 'row_count' => 1])
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Lookup table sync completed', ['table_key' => 'utm_campaigns', 'source' => 'Google Ads', 'rows_synced' => 1])
            ->once();

        $this->useCase->execute();
    }

    #[Test]
    public function it_syncs_multiple_rows_to_mixpanel(): void
    {
        $this->setupProviderMetadata('products', 'Shopwired', ['sku', 'name', 'price']);

        $rows = [
            ['SKU001', 'Product One', '10.00'],
            ['SKU002', 'Product Two', '20.00'],
            ['SKU003', 'Product Three', '30.00'],
        ];

        $this->provider
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn($rows);

        $this->mixpanelClient
            ->shouldReceive('replaceLookupTable')
            ->once()
            ->with('products', ['sku', 'name', 'price'], $rows);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Lookup table sync completed', ['table_key' => 'products', 'source' => 'Shopwired', 'rows_synced' => 3])
            ->once();

        $this->useCase->execute();
    }

    #[Test]
    public function it_preserves_row_order(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['id', 'name']);

        $rows = [
            ['999', 'Last'],
            ['111', 'First'],
            ['555', 'Middle'],
        ];

        $this->provider
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn($rows);

        $this->mixpanelClient
            ->shouldReceive('replaceLookupTable')
            ->once()
            ->withArgs(static function (string $tableKey, array $headers, array $receivedRows) use ($rows): bool {
                self::assertSame($rows, $receivedRows);

                return true;
            });

        $this->useCase->execute();
    }

    // ========================================================================
    // Empty Results Handling
    // ========================================================================

    #[Test]
    public function it_throws_exception_when_no_rows_found(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['id', 'name']);

        $this->provider
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn([]);

        $this->mixpanelClient
            ->shouldNotReceive('replaceLookupTable');

        $this->loggerMock
            ->shouldReceive('error')
            ->with('No data found from source - this may indicate an API issue or account misconfiguration', Mockery::any())
            ->once();

        $this->expectException(UnexpectedApiResultException::class);
        $this->expectExceptionMessage('Unexpected result from Google Ads');

        $this->useCase->execute();
    }

    #[Test]
    public function it_logs_error_and_does_not_call_mixpanel_when_no_rows_found(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['id', 'name']);

        $this->provider
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn([]);

        $this->mixpanelClient
            ->shouldNotReceive('replaceLookupTable');

        $this->loggerMock
            ->shouldReceive('error')
            ->with(
                'No data found from source - this may indicate an API issue or account misconfiguration',
                ['table_key' => 'utm_campaigns', 'source' => 'Google Ads'],
            )
            ->once();

        try {
            $this->useCase->execute();
        } catch (UnexpectedApiResultException) {
            // Expected
        }
    }

    // ========================================================================
    // Provider Exceptions
    // ========================================================================

    #[Test]
    public function it_propagates_provider_api_exception(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['id', 'name']);

        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->provider
            ->shouldReceive('fetchRows')
            ->once()
            ->andThrow($exception);

        $this->mixpanelClient
            ->shouldNotReceive('replaceLookupTable');

        $this->expectExceptionObject($exception);

        $this->useCase->execute();
    }

    #[Test]
    public function it_logs_start_before_provider_exception(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['id', 'name']);

        $exception = new ExternalServiceUnavailableException('Google Ads');

        $this->provider
            ->shouldReceive('fetchRows')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting lookup table sync', Mockery::any())
            ->once();

        try {
            $this->useCase->execute();
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    // ========================================================================
    // Mixpanel Client Exceptions
    // ========================================================================

    #[Test]
    public function it_propagates_mixpanel_api_exception(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['id', 'name']);

        $this->provider
            ->shouldReceive('fetchRows')
            ->once()
            ->andReturn([['123', 'Test']]);

        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->mixpanelClient
            ->shouldReceive('replaceLookupTable')
            ->once()
            ->andThrow($exception);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage('Mixpanel');

        $this->useCase->execute();
    }

    #[Test]
    public function it_does_not_log_completion_when_mixpanel_fails(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['id', 'name']);

        $this->provider
            ->shouldReceive('fetchRows')
            ->andReturn([['123', 'Test']]);

        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->mixpanelClient
            ->shouldReceive('replaceLookupTable')
            ->andThrow($exception);

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting lookup table sync', Mockery::any())
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Retrieved data from source', Mockery::any())
            ->once();

        // Should NOT receive completion log
        $this->loggerMock
            ->shouldNotReceive('info')
            ->with('Lookup table sync completed', Mockery::any());

        try {
            $this->useCase->execute();
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    // ========================================================================
    // Logging Verification
    // ========================================================================

    #[Test]
    public function it_logs_start_message_with_table_key_and_source(): void
    {
        $this->setupProviderMetadata('products', 'Shopwired', ['sku', 'name']);

        $this->provider
            ->shouldReceive('fetchRows')
            ->andReturn([['SKU001', 'Test Product']]);

        $this->mixpanelClient
            ->shouldReceive('replaceLookupTable')
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Starting lookup table sync', ['table_key' => 'products', 'source' => 'Shopwired'])
            ->once();

        $this->useCase->execute();
    }

    #[Test]
    public function it_logs_completion_with_exact_row_count(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['id', 'name']);

        $rows = [
            ['1', 'One'],
            ['2', 'Two'],
            ['3', 'Three'],
        ];

        $this->provider
            ->shouldReceive('fetchRows')
            ->andReturn($rows);

        $this->mixpanelClient
            ->shouldReceive('replaceLookupTable')
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Lookup table sync completed', ['table_key' => 'utm_campaigns', 'source' => 'Google Ads', 'rows_synced' => 3])
            ->once();

        $this->useCase->execute();
    }

    #[Test]
    public function it_logs_retrieved_row_count(): void
    {
        $this->setupProviderMetadata('utm_campaigns', 'Google Ads', ['id', 'name']);

        $rows = [
            ['1', 'One'],
            ['2', 'Two'],
        ];

        $this->provider
            ->shouldReceive('fetchRows')
            ->andReturn($rows);

        $this->mixpanelClient
            ->shouldReceive('replaceLookupTable')
            ->once();

        $this->loggerMock
            ->shouldReceive('info')
            ->with('Retrieved data from source', ['table_key' => 'utm_campaigns', 'source' => 'Google Ads', 'row_count' => 2])
            ->once();

        $this->useCase->execute();
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * @param array<int, string> $headers
     */
    private function setupProviderMetadata(string $tableKey, string $sourceName, array $headers): void
    {
        $this->provider
            ->shouldReceive('getTableKey')
            ->andReturn($tableKey);

        $this->provider
            ->shouldReceive('getSourceName')
            ->andReturn($sourceName);

        $this->provider
            ->shouldReceive('getHeaders')
            ->andReturn($headers);
    }
}
