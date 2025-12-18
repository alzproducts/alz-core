<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout;

use App\Domain\CustomerService\ValueObjects\ConversationTag;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\TagResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HelpScoutResponseParser Unit Tests.
 *
 * Tests the HAL response parsing including:
 * - Embedded collection extraction
 * - DTO-to-Domain transformation
 * - Search/filter operations
 * - Error handling for invalid responses
 */
#[CoversClass(HelpScoutResponseParser::class)]
final class HelpScoutResponseParserTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    /*
    |--------------------------------------------------------------------------
    | parseEmbeddedCollection() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function parse_embedded_collection_returns_dtos_from_valid_hal_response(): void
    {
        $json = [
            '_embedded' => [
                'tags' => [
                    ['id' => 1, 'tag' => 'urgent', 'color' => '#ff0000'],
                    ['id' => 2, 'tag' => 'billing', 'color' => '#00ff00'],
                ],
            ],
        ];

        $result = HelpScoutResponseParser::parseEmbeddedCollection($json, 'tags', TagResponse::class);

        $this->assertCount(2, $result);
        $this->assertContainsOnlyInstancesOf(TagResponse::class, $result);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame('urgent', $result[0]->tag);
        $this->assertSame('#ff0000', $result[0]->color);
        $this->assertSame(2, $result[1]->id);
        $this->assertSame('billing', $result[1]->tag);
    }

    #[Test]
    public function parse_embedded_collection_returns_empty_array_when_embedded_is_empty(): void
    {
        $json = [
            '_embedded' => [
                'tags' => [],
            ],
        ];

        $result = HelpScoutResponseParser::parseEmbeddedCollection($json, 'tags', TagResponse::class);

        $this->assertSame([], $result);
    }

    #[Test]
    public function parse_embedded_collection_throws_when_json_is_not_array(): void
    {
        Log::shouldReceive('critical')
            ->with('HelpScout API response validation failed', Mockery::on(static fn(array $context) => $context['error'] === 'Expected array response for tags'))
            ->once();

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Expected array response for tags');

        HelpScoutResponseParser::parseEmbeddedCollection('not-an-array', 'tags', TagResponse::class);
    }

    #[Test]
    public function parse_embedded_collection_throws_when_json_is_null(): void
    {
        Log::shouldReceive('critical')
            ->with('HelpScout API response validation failed', Mockery::type('array'))
            ->once();

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Expected array response for tags');

        HelpScoutResponseParser::parseEmbeddedCollection(null, 'tags', TagResponse::class);
    }

    #[Test]
    public function parse_embedded_collection_throws_when_embedded_key_missing(): void
    {
        $json = [
            'data' => 'some-data',
        ];

        Log::shouldReceive('critical')
            ->with('HelpScout API response validation failed', Mockery::on(static fn(array $context) => $context['error'] === 'Missing _embedded in response'))
            ->once();

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Missing _embedded in response');

        HelpScoutResponseParser::parseEmbeddedCollection($json, 'tags', TagResponse::class);
    }

    #[Test]
    public function parse_embedded_collection_throws_when_resource_key_missing(): void
    {
        $json = [
            '_embedded' => [
                'mailboxes' => [],
            ],
        ];

        Log::shouldReceive('critical')
            ->with('HelpScout API response validation failed', Mockery::on(static fn(array $context) => $context['error'] === 'Missing _embedded.tags in response'))
            ->once();

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('Missing _embedded.tags in response');

        HelpScoutResponseParser::parseEmbeddedCollection($json, 'tags', TagResponse::class);
    }

    #[Test]
    public function parse_embedded_collection_throws_on_invalid_dto_structure(): void
    {
        $json = [
            '_embedded' => [
                'tags' => [
                    ['invalid' => 'structure'], // Missing required fields
                ],
            ],
        ];

        Log::shouldReceive('critical')
            ->with('HelpScout API response validation failed', Mockery::type('array'))
            ->once();

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('API returned invalid data structure');

        HelpScoutResponseParser::parseEmbeddedCollection($json, 'tags', TagResponse::class);
    }

    /*
    |--------------------------------------------------------------------------
    | parseEmbeddedCollectionToDomain() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function parse_embedded_collection_to_domain_returns_domain_objects(): void
    {
        $json = [
            '_embedded' => [
                'tags' => [
                    ['id' => 1, 'tag' => 'urgent', 'color' => '#ff0000'],
                    ['id' => 2, 'tag' => 'billing', 'color' => '#00ff00'],
                ],
            ],
        ];

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->andReturn($json);

        $result = HelpScoutResponseParser::parseEmbeddedCollectionToDomain(
            $response,
            'tags',
            TagResponse::class,
        );

        $this->assertCount(2, $result);
        $this->assertContainsOnlyInstancesOf(ConversationTag::class, $result);
        $this->assertSame(1, $result[0]->id);
        $this->assertSame('urgent', $result[0]->name);
        $this->assertSame('#ff0000', $result[0]->color);
    }

    #[Test]
    public function parse_embedded_collection_to_domain_returns_empty_array_when_no_items(): void
    {
        $json = [
            '_embedded' => [
                'tags' => [],
            ],
        ];

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->andReturn($json);

        $result = HelpScoutResponseParser::parseEmbeddedCollectionToDomain(
            $response,
            'tags',
            TagResponse::class,
        );

        $this->assertSame([], $result);
    }

    /*
    |--------------------------------------------------------------------------
    | findDomainInEmbeddedCollection() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function find_domain_in_embedded_collection_returns_matching_item(): void
    {
        $json = [
            '_embedded' => [
                'tags' => [
                    ['id' => 1, 'tag' => 'urgent', 'color' => '#ff0000'],
                    ['id' => 2, 'tag' => 'billing', 'color' => '#00ff00'],
                    ['id' => 3, 'tag' => 'support', 'color' => '#0000ff'],
                ],
            ],
        ];

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->andReturn($json);

        $result = HelpScoutResponseParser::findDomainInEmbeddedCollection(
            $response,
            'tags',
            TagResponse::class,
            static fn(TagResponse $tag) => $tag->tag === 'billing',
        );

        $this->assertInstanceOf(ConversationTag::class, $result);
        $this->assertSame(2, $result->id);
        $this->assertSame('billing', $result->name);
    }

    #[Test]
    public function find_domain_in_embedded_collection_returns_null_when_no_match(): void
    {
        $json = [
            '_embedded' => [
                'tags' => [
                    ['id' => 1, 'tag' => 'urgent', 'color' => '#ff0000'],
                ],
            ],
        ];

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->andReturn($json);

        $result = HelpScoutResponseParser::findDomainInEmbeddedCollection(
            $response,
            'tags',
            TagResponse::class,
            static fn(TagResponse $tag) => $tag->tag === 'nonexistent',
        );

        $this->assertNull($result);
    }

    #[Test]
    public function find_domain_in_embedded_collection_returns_null_on_empty_collection(): void
    {
        $json = [
            '_embedded' => [
                'tags' => [],
            ],
        ];

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->once()->andReturn($json);

        $result = HelpScoutResponseParser::findDomainInEmbeddedCollection(
            $response,
            'tags',
            TagResponse::class,
            static fn(TagResponse $tag) => true, // Always match
        );

        $this->assertNull($result);
    }

    /*
    |--------------------------------------------------------------------------
    | Exception Properties Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function exception_contains_helpscout_service_name(): void
    {
        Log::shouldReceive('critical')->once();

        try {
            HelpScoutResponseParser::parseEmbeddedCollection('invalid', 'test', TagResponse::class);
            $this->fail('Expected InvalidApiResponseException');
        } catch (InvalidApiResponseException $e) {
            $this->assertSame('HelpScout', $e->serviceName);
        }
    }
}
