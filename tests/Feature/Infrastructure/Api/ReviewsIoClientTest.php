<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Api;

use App\Domain\Review\Rating;
use App\Domain\Review\Validation\ValidSku;
use App\Infrastructure\Api\ReviewsIo\ReviewsIoClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * ReviewsIoClient Feature Tests
 * Tests the Reviews.io API client implementation covering:
 * - Success paths (single/multiple SKUs, empty responses)
 * - Validation failures (empty array, invalid SKUs, batch limits)
 * - API errors (HTTP 4xx/5xx, network failures)
 * - HTTP client configuration (query params, retry logic)
 * - Data transformation (snake_case → camelCase)
 */
#[CoversClass(ValidSku::class)]
#[CoversClass(ReviewsIoClient::class)]
#[CoversClass(ReviewsIoClient::class)]
final class ReviewsIoClientTest extends TestCase
{
    private const string TEST_API_KEY = 'test-api-key';
    private const string TEST_STORE_ID = 'test-store-id';

    private ReviewsIoClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new ReviewsIoClient(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            timeout: 30,
            retryTimes: 3,
            retryDelay: 100,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_retrieves_rating_for_single_sku_provided_as_string(): void
    {
        Http::fake([
            '*' => Http::response([
                [
                    'sku' => 'FLP-01',
                    'average_rating' => 4.5,
                    'num_ratings' => 362,
                ],
            ]),
        ]);

        $result = $this->client->getProductRatingBatch('FLP-01');

        // Verify HTTP request was sent correctly
        Http::assertSent(function (Request $request) {
            $this->assertStringContainsString('product/rating-batch', $request->url());

            // Verify query parameters in URL
            $this->assertStringContainsString('sku=FLP-01', $request->url());
            $this->assertStringContainsString('apikey=' . self::TEST_API_KEY, $request->url());
            $this->assertStringContainsString('store=' . self::TEST_STORE_ID, $request->url());

            return true;
        });

        // Verify response structure and data
        $this->assertInstanceOf(DataCollection::class, $result);
        $this->assertCount(1, $result);
        $this->assertInstanceOf(Rating::class, $result[0]);
        $this->assertSame('FLP-01', $result[0]->sku);
        $this->assertSame(4.5, $result[0]->averageRating);
        $this->assertSame(362, $result[0]->numRatings);
    }

    #[Test]
    public function it_retrieves_rating_for_single_sku_provided_as_array(): void
    {
        Http::fake([
            '*' => Http::response([
                [
                    'sku' => 'E2L-PA481101',
                    'average_rating' => 4.625,
                    'num_ratings' => 16,
                ],
            ]),
        ]);

        $result = $this->client->getProductRatingBatch(['E2L-PA481101']);

        $this->assertInstanceOf(DataCollection::class, $result);
        $this->assertCount(1, $result);
        $this->assertSame('E2L-PA481101', $result[0]->sku);
        $this->assertSame(4.625, $result[0]->averageRating);
        $this->assertSame(16, $result[0]->numRatings);
    }

    #[Test]
    public function it_retrieves_ratings_for_multiple_skus_in_batch(): void
    {
        Http::fake([
            '*' => Http::response([
                ['sku' => 'FLP-01', 'average_rating' => 4.5, 'num_ratings' => 362],
                ['sku' => 'E2L-PA481101', 'average_rating' => 4.625, 'num_ratings' => 16],
                ['sku' => 'SKU-123', 'average_rating' => 5.0, 'num_ratings' => 100],
            ]),
        ]);

        $result = $this->client->getProductRatingBatch(['FLP-01', 'E2L-PA481101', 'SKU-123']);

        // Verify SKUs are semicolon-joined in URL
        Http::assertSent(function (Request $request) {
            $this->assertStringContainsString('sku=FLP-01%3BE2L-PA481101%3BSKU-123', $request->url());

            return true;
        });

        $this->assertCount(3, $result);
        $this->assertSame('FLP-01', $result[0]->sku);
        $this->assertSame('E2L-PA481101', $result[1]->sku);
        $this->assertSame('SKU-123', $result[2]->sku);
        $this->assertSame(5.0, $result[2]->averageRating);
    }

    #[Test]
    public function it_returns_empty_collection_when_api_returns_empty_array(): void
    {
        Http::fake(['*' => Http::response([])]);

        $result = $this->client->getProductRatingBatch(['NONEXISTENT-SKU']);

        $this->assertInstanceOf(DataCollection::class, $result);
        $this->assertCount(0, $result);
    }

    #[Test]
    public function it_correctly_maps_snake_case_api_fields_to_camel_case_dto_properties(): void
    {
        Http::fake([
            '*' => Http::response([
                [
                    'sku' => 'TEST-SKU',
                    'average_rating' => 3.75, // snake_case
                    'num_ratings' => 42,      // snake_case
                ],
            ]),
        ]);

        $result = $this->client->getProductRatingBatch('TEST-SKU');

        // Verify Spatie Data SnakeCaseMapper worked
        $this->assertSame(3.75, $result[0]->averageRating); // camelCase property
        $this->assertSame(42, $result[0]->numRatings);     // camelCase property
    }

    #[Test]
    public function it_handles_sku_with_all_allowed_special_characters(): void
    {
        $sku = 'SKU-01_test.v2(new)/final';

        Http::fake(['*' => Http::response([
            ['sku' => $sku, 'average_rating' => 4.0, 'num_ratings' => 10],
        ])]);

        $result = $this->client->getProductRatingBatch($sku);

        $this->assertCount(1, $result);
        $this->assertSame($sku, $result[0]->sku);
    }

    #[Test]
    public function it_handles_maximum_batch_size_of_100_skus(): void
    {
        $skus = \array_map(static fn($i) => "SKU-{$i}", \range(1, 100));

        $response = \array_map(
            static fn($sku) => ['sku' => $sku, 'average_rating' => 4.5, 'num_ratings' => 10],
            $skus,
        );

        Http::fake(['*' => Http::response($response)]);

        $result = $this->client->getProductRatingBatch($skus);

        $this->assertCount(100, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Failure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_validation_exception_for_empty_sku_array(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The skus field is required.');

        $this->client->getProductRatingBatch([]);
    }

    #[Test]
    public function it_throws_validation_exception_when_exceeding_batch_size_limit_of_100(): void
    {
        $skus = \array_fill(0, 101, 'SKU');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The skus field must not have more than 100 items.');

        $this->client->getProductRatingBatch($skus);
    }

    #[Test]
    #[DataProvider('invalidSkuProvider')]
    public function it_throws_validation_exception_for_invalid_sku(string $invalidSku, string $expectedMessage): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->client->getProductRatingBatch([$invalidSku]);
    }

    public static function invalidSkuProvider(): array
    {
        return [
            'script tag' => ['SKU-<script>', 'The skus.0 contains invalid characters.'],
            'emoji' => ['SKU-🎉', 'The skus.0 contains invalid characters.'],
            'ampersand' => ['SKU&123', 'The skus.0 contains invalid characters.'],
            'percent sign' => ['SKU%OFF', 'The skus.0 contains invalid characters.'],
            'at sign' => ['SKU@EMAIL', 'The skus.0 contains invalid characters.'],
        ];
    }

    #[Test]
    public function it_throws_validation_exception_for_empty_string_sku(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The skus.0 field is required.');

        $this->client->getProductRatingBatch(['']);
    }

    #[Test]
    public function it_throws_validation_exception_for_sku_exceeding_50_characters(): void
    {
        $longSku = \str_repeat('A', 51);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The skus.0 field must not be greater than 50 characters.');

        $this->client->getProductRatingBatch([$longSku]);
    }

    #[Test]
    public function it_throws_validation_exception_for_sku_at_exactly_51_characters_boundary(): void
    {
        $boundarySku = \str_repeat('X', 51);

        $this->expectException(ValidationException::class);

        $this->client->getProductRatingBatch([$boundarySku]);
    }

    #[Test]
    public function it_accepts_sku_at_exactly_50_characters_boundary(): void
    {
        $sku50 = \str_repeat('Y', 50);

        Http::fake(['*' => Http::response([
            ['sku' => $sku50, 'average_rating' => 4.0, 'num_ratings' => 5],
        ])]);

        $result = $this->client->getProductRatingBatch([$sku50]);

        $this->assertCount(1, $result);
        $this->assertSame($sku50, $result[0]->sku);
    }

    /*
    |--------------------------------------------------------------------------
    | API Error Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_request_exception_on_http_404_response(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Not Found'], 404)]);

        $this->expectException(RequestException::class);

        $this->client->getProductRatingBatch(['SKU-404']);
    }

    #[Test]
    public function it_throws_request_exception_on_http_500_server_error(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Internal Server Error'], 500)]);

        $this->expectException(RequestException::class);

        $this->client->getProductRatingBatch(['SKU-500']);
    }

    #[Test]
    public function it_throws_request_exception_on_http_401_unauthorized(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->expectException(RequestException::class);

        $this->client->getProductRatingBatch(['SKU-401']);
    }

    #[Test]
    public function it_throws_request_exception_on_http_422_validation_error_from_api(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Validation failed',
            'errors' => ['sku' => ['Invalid SKU format']],
        ], 422)]);

        $this->expectException(RequestException::class);

        $this->client->getProductRatingBatch(['INVALID']);
    }

    #[Test]
    public function it_throws_connection_exception_on_network_failure(): void
    {
        Http::fake(static fn() => throw new ConnectionException('Could not resolve host'));

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Could not resolve host');

        $this->client->getProductRatingBatch(['SKU-NETWORK-FAIL']);
    }

    #[Test]
    public function it_throws_connection_exception_on_timeout(): void
    {
        Http::fake(static fn() => throw new ConnectionException('Connection timed out'));

        $this->expectException(ConnectionException::class);

        $this->client->getProductRatingBatch(['SKU-TIMEOUT']);
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Configuration Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_configures_http_client_with_correct_base_url(): void
    {
        Http::fake();

        $this->client->getProductRatingBatch('TEST-SKU');

        Http::assertSent(function (Request $request) {
            $this->assertStringStartsWith('https://api.reviews.co.uk/', $request->url());

            return true;
        });
    }

    #[Test]
    public function it_sends_required_query_parameters_in_every_request(): void
    {
        Http::fake();

        $this->client->getProductRatingBatch('TEST-SKU');

        Http::assertSent(function (Request $request) {
            $url = $request->url();
            $this->assertStringContainsString('apikey=' . self::TEST_API_KEY, $url);
            $this->assertStringContainsString('store=' . self::TEST_STORE_ID, $url);
            $this->assertStringContainsString('sku=TEST-SKU', $url);

            return true;
        });
    }

    #[Test]
    public function it_uses_get_method_for_rating_batch_endpoint(): void
    {
        Http::fake();

        $this->client->getProductRatingBatch('TEST-SKU');

        Http::assertSent(function (Request $request) {
            $this->assertSame('GET', $request->method());

            return true;
        });
    }

    #[Test]
    public function it_configures_retry_logic_with_custom_parameters(): void
    {
        // Note: Laravel's Http::fake() doesn't expose retry configuration directly
        // This test verifies the client accepts retry parameters without error
        $client = new ReviewsIoClient(
            apiKey: self::TEST_API_KEY,
            storeId: self::TEST_STORE_ID,
            timeout: 10,
            retryTimes: 5,
            retryDelay: 200,
        );

        Http::fake(['*' => Http::response([])]);

        // Should not throw exception
        $result = $client->getProductRatingBatch('SKU');

        $this->assertInstanceOf(DataCollection::class, $result);
    }
}
