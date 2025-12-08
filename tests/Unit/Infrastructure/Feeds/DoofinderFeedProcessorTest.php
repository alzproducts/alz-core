<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Feeds;

use App\Application\Contracts\RemoteStorageInterface;
use App\Application\Feeds\ProductSearchFeedProcessingResult;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\MalformedFeedDataException;
use App\Infrastructure\Feeds\DoofinderFeedProcessor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * DoofinderFeedProcessor Unit Tests.
 *
 * Tests the feed processing logic including:
 * - HTTP fetching with meta-refresh redirect handling
 * - XML transformation (title substitution)
 * - Namespace handling (non-namespaced and g: prefixed)
 * - Validation of feed structure
 * - Error handling for HTTP and XML failures
 */
#[CoversClass(DoofinderFeedProcessor::class)]
final class DoofinderFeedProcessorTest extends TestCase
{
    private RemoteStorageInterface&MockInterface $mockStorage;
    private LoggerInterface&MockInterface $mockLogger;
    private DoofinderFeedProcessor $processor;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockStorage = Mockery::mock(RemoteStorageInterface::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldReceive('info', 'debug', 'error')->byDefault();

        $this->processor = new DoofinderFeedProcessor(
            $this->mockStorage,
            $this->mockLogger,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path - Non-namespaced Elements
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_processes_feed_with_non_namespaced_title_and_d_title(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response($this->createValidFeedXml()),
        ]);

        $uploadedContent = null;
        $this->mockStorage
            ->shouldReceive('put')
            ->once()
            ->with('feeds/output.xml', Mockery::capture($uploadedContent));

        $result = $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        $this->assertInstanceOf(ProductSearchFeedProcessingResult::class, $result);
        $this->assertSame(2, $result->itemsProcessed);
        $this->assertSame(2, $result->titlesSubstituted);
        $this->assertGreaterThan(0.0, $result->durationSeconds);

        // Verify title was substituted
        $this->assertStringContainsString('Display Title One', $uploadedContent);
        $this->assertStringContainsString('Display Title Two', $uploadedContent);
        $this->assertStringNotContainsString('>original title one<', $uploadedContent);
    }

    #[Test]
    public function it_returns_correct_counts_when_some_items_have_empty_d_title(): void
    {
        $xml = $this->createFeedXmlWithEmptyDTitle();

        Http::fake([
            'https://example.com/feed' => Http::response($xml),
        ]);

        $this->mockStorage->shouldReceive('put')->once();

        $result = $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        $this->assertSame(3, $result->itemsProcessed);
        $this->assertSame(2, $result->titlesSubstituted);
    }

    #[Test]
    public function it_does_not_substitute_when_d_title_is_empty(): void
    {
        $xml = $this->createFeedXmlWithEmptyDTitle();

        Http::fake([
            'https://example.com/feed' => Http::response($xml),
        ]);

        $uploadedContent = null;
        $this->mockStorage
            ->shouldReceive('put')
            ->once()
            ->with('feeds/output.xml', Mockery::capture($uploadedContent));

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        // Item with empty d_title should keep original title
        $this->assertStringContainsString('>original title three<', $uploadedContent);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path - Google Namespaced Elements
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_processes_feed_with_google_namespaced_title_and_d_title(): void
    {
        $xml = $this->createGoogleNamespacedFeedXml();

        Http::fake([
            'https://example.com/feed' => Http::response($xml),
        ]);

        $uploadedContent = null;
        $this->mockStorage
            ->shouldReceive('put')
            ->once()
            ->with('feeds/output.xml', Mockery::capture($uploadedContent));

        $result = $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        $this->assertSame(2, $result->itemsProcessed);
        $this->assertSame(2, $result->titlesSubstituted);
        $this->assertStringContainsString('Display Title One', $uploadedContent);
    }

    /*
    |--------------------------------------------------------------------------
    | Meta-Refresh Redirect Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_follows_meta_refresh_redirect(): void
    {
        $htmlWithRedirect = $this->createHtmlWithMetaRefresh('https://s3.example.com/actual-feed.xml');

        Http::fake([
            'https://example.com/feed' => Http::response($htmlWithRedirect),
            'https://s3.example.com/actual-feed.xml' => Http::response($this->createValidFeedXml()),
        ]);

        $this->mockStorage->shouldReceive('put')->once();

        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(static fn(string $message): bool => \str_contains($message, 'meta-refresh redirect'));

        $result = $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        $this->assertSame(2, $result->itemsProcessed);
    }

    #[Test]
    public function it_decodes_html_entities_in_redirect_url(): void
    {
        $htmlWithEncodedUrl = $this->createHtmlWithMetaRefresh('https://s3.example.com/feed.xml?foo=1&amp;bar=2');

        Http::fake([
            'https://example.com/feed' => Http::response($htmlWithEncodedUrl),
            'https://s3.example.com/feed.xml?foo=1&bar=2' => Http::response($this->createValidFeedXml()),
        ]);

        $this->mockStorage->shouldReceive('put')->once();

        $result = $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        $this->assertSame(2, $result->itemsProcessed);
    }

    #[Test]
    public function it_does_not_attempt_redirect_for_xml_content(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response($this->createValidFeedXml()),
        ]);

        $this->mockStorage->shouldReceive('put')->once();

        $this->mockLogger
            ->shouldNotReceive('debug')
            ->withArgs(static fn(string $message): bool => \str_contains($message, 'meta-refresh'));

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    /*
    |--------------------------------------------------------------------------
    | HTTP Error Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_external_service_unavailable_on_http_500(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response('Server Error', 500),
        ]);

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Doofinder Feed' is unavailable");

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_throws_external_service_unavailable_on_http_503(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response('Service Unavailable', 503),
        ]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_throws_external_service_unavailable_with_retry_after_on_http_error(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response('Error', 500),
        ]);

        try {
            $this->processor->process('https://example.com/feed', 'feeds/output.xml');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Doofinder Feed', $e->serviceName);
            $this->assertSame(300, $e->retryAfter);
        }
    }

    #[Test]
    public function it_throws_external_service_unavailable_on_connection_exception(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection timed out');
        });

        $this->expectException(ExternalServiceUnavailableException::class);
        $this->expectExceptionMessage("External service 'Doofinder Feed' is unavailable");

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_preserves_connection_exception_as_previous(): void
    {
        $connectionException = new ConnectionException('Network unreachable');

        Http::fake(static function () use ($connectionException): never {
            throw $connectionException;
        });

        try {
            $this->processor->process('https://example.com/feed', 'feeds/output.xml');
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame($connectionException, $e->getPrevious());
        }
    }

    #[Test]
    public function it_logs_error_on_http_failure_with_full_context(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response('Error', 500),
        ]);

        // Validate ALL log context items - catches RemoveArrayItem mutations
        $this->mockLogger
            ->shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'Feed fetch failed')
                    && $context['service'] === 'Doofinder Feed'
                    && $context['url'] === 'https://example.com/feed'
                    && $context['status'] === 500);

        try {
            $this->processor->process('https://example.com/feed', 'feeds/output.xml');
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    #[Test]
    public function it_logs_connection_error_with_full_context(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('Connection timed out');
        });

        // Validate ALL log context items - catches RemoveArrayItem mutations
        $this->mockLogger
            ->shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'connection failed')
                    && $context['service'] === 'Doofinder Feed'
                    && $context['url'] === 'https://example.com/feed'
                    && \str_contains($context['error'], 'Connection timed out'));

        try {
            $this->processor->process('https://example.com/feed', 'feeds/output.xml');
        } catch (ExternalServiceUnavailableException) {
            // Expected
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Feed Validation Errors
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_malformed_feed_exception_for_empty_feed(): void
    {
        $emptyFeed = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Empty Feed</title>
    </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed' => Http::response($emptyFeed),
        ]);

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('Feed contains no items');

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_throws_malformed_feed_exception_when_title_missing(): void
    {
        $feedMissingTitle = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <item>
            <link>https://example.com/product</link>
            <d_title>Display Title</d_title>
        </item>
    </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed' => Http::response($feedMissingTitle),
        ]);

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('missing required title element');

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_throws_malformed_feed_exception_when_d_title_missing(): void
    {
        $feedMissingDTitle = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <item>
            <link>https://example.com/product</link>
            <title>Product Title</title>
        </item>
    </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed' => Http::response($feedMissingDTitle),
        ]);

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('missing required d_title element');

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_throws_malformed_feed_exception_when_both_title_and_d_title_missing(): void
    {
        $feedMissingBoth = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <item>
            <link>https://example.com/product</link>
            <description>Product description</description>
        </item>
    </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed' => Http::response($feedMissingBoth),
        ]);

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('missing both title and d_title');

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_includes_feed_name_in_malformed_exception(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response('<invalid'),
        ]);

        try {
            $this->processor->process('https://example.com/feed', 'feeds/output.xml');
            $this->fail('Expected MalformedFeedDataException');
        } catch (MalformedFeedDataException $e) {
            $this->assertSame('Doofinder Feed', $e->feedName);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Malformed XML Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_malformed_feed_exception_for_invalid_xml(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response('<invalid xml without closing tag'),
        ]);

        $this->expectException(MalformedFeedDataException::class);
        // XMLReader may fail at parse time or read time depending on the error
        $this->expectExceptionMessage('malformed');

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_throws_malformed_feed_exception_for_non_xml_content(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response('This is not XML at all'),
        ]);

        $this->expectException(MalformedFeedDataException::class);

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Verification
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_start_with_correct_context(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response($this->createValidFeedXml()),
        ]);

        $this->mockStorage->shouldReceive('put')->once();

        // Validate ALL log context items - catches RemoveArrayItem mutations
        $this->mockLogger
            ->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'Starting feed processing')
                    && $context['service'] === 'Doofinder Feed'
                    && $context['source_url'] === 'https://example.com/feed');

        $this->mockLogger->shouldReceive('info')->once(); // completion log

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_logs_completion_with_all_context(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response($this->createValidFeedXml()),
        ]);

        $this->mockStorage->shouldReceive('put')->once();

        $this->mockLogger->shouldReceive('info')->once(); // start log

        // Validate ALL log context items - catches RemoveArrayItem mutations
        $this->mockLogger
            ->shouldReceive('info')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'Feed processing completed')
                    && $context['service'] === 'Doofinder Feed'
                    && $context['items_processed'] === 2
                    && $context['titles_substituted'] === 2
                    && isset($context['duration_seconds']));

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    /*
    |--------------------------------------------------------------------------
    | Storage Integration
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_calls_storage_put_with_correct_path(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response($this->createValidFeedXml()),
        ]);

        $this->mockStorage
            ->shouldReceive('put')
            ->once()
            ->with('feeds/custom-output.xml', Mockery::type('string'));

        $this->processor->process('https://example.com/feed', 'feeds/custom-output.xml');
    }

    /*
    |--------------------------------------------------------------------------
    | XML Structure Preservation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_preserves_xml_attributes_in_output(): void
    {
        $xmlWithAttributes = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
    <channel>
        <item id="123" category="widgets">
            <title>Original</title>
            <d_title>Display</d_title>
            <g:price currency="GBP">29.99</g:price>
        </item>
    </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed' => Http::response($xmlWithAttributes),
        ]);

        $uploadedContent = null;
        $this->mockStorage
            ->shouldReceive('put')
            ->once()
            ->with('feeds/output.xml', Mockery::capture($uploadedContent));

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        // Verify attributes are preserved with correct quoting
        $this->assertStringContainsString('version="2.0"', $uploadedContent);
        $this->assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $uploadedContent);
    }

    #[Test]
    public function it_handles_cdata_content_correctly(): void
    {
        $xmlWithCdata = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title><![CDATA[Store Name]]></title>
        <item>
            <title>Original</title>
            <d_title>Display Title</d_title>
            <description><![CDATA[HTML <b>content</b>]]></description>
        </item>
    </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed' => Http::response($xmlWithCdata),
        ]);

        $uploadedContent = null;
        $this->mockStorage
            ->shouldReceive('put')
            ->once()
            ->with('feeds/output.xml', Mockery::capture($uploadedContent));

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        // CDATA is converted to escaped text by XMLReader/SimpleXML
        $this->assertStringContainsString('Display Title', $uploadedContent);
    }

    #[Test]
    public function it_preserves_self_closing_empty_elements(): void
    {
        // XML with self-closing empty element
        $xmlWithSelfClosingElement = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Store</title>
        <empty_tag/>
        <item>
            <title>Original</title>
            <d_title>Display</d_title>
        </item>
    </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed' => Http::response($xmlWithSelfClosingElement),
        ]);

        $uploadedContent = null;
        $this->mockStorage
            ->shouldReceive('put')
            ->once()
            ->with('feeds/output.xml', Mockery::capture($uploadedContent));

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        // Self-closing element should be preserved as self-closing
        $this->assertStringContainsString('<empty_tag/>', $uploadedContent);
        $this->assertStringContainsString('Display', $uploadedContent);
    }

    #[Test]
    public function it_preserves_regular_closing_elements(): void
    {
        $xmlWithRegularElements = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Store</title>
        <item>
            <title>Original</title>
            <d_title>Display</d_title>
            <description>Some description</description>
        </item>
    </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed' => Http::response($xmlWithRegularElements),
        ]);

        $uploadedContent = null;
        $this->mockStorage
            ->shouldReceive('put')
            ->once()
            ->with('feeds/output.xml', Mockery::capture($uploadedContent));

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        // Regular elements should have separate closing tags
        $this->assertStringContainsString('<channel>', $uploadedContent);
        $this->assertStringContainsString('</channel>', $uploadedContent);
        $this->assertStringContainsString('Some description', $uploadedContent);
    }

    #[Test]
    public function it_escapes_special_xml_characters_in_text_content(): void
    {
        $xmlWithSpecialChars = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Store &amp; More</title>
        <item>
            <title>Original</title>
            <d_title>Display &lt;New&gt;</d_title>
        </item>
    </channel>
</rss>
XML;

        Http::fake([
            'https://example.com/feed' => Http::response($xmlWithSpecialChars),
        ]);

        $uploadedContent = null;
        $this->mockStorage
            ->shouldReceive('put')
            ->once()
            ->with('feeds/output.xml', Mockery::capture($uploadedContent));

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');

        // Verify the escaped content is in the output
        $this->assertStringContainsString('Display', $uploadedContent);
    }

    /*
    |--------------------------------------------------------------------------
    | Feed Validation Debug Logging
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_feed_structure_validation_for_non_namespaced(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response($this->createValidFeedXml()),
        ]);

        $this->mockStorage->shouldReceive('put')->once();

        // Validate debug log with ALL context items - catches RemoveArrayItem mutations
        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(
                static fn(string $message, array $context): bool => \str_contains($message, 'Feed structure validated')
                    && $context['service'] === 'Doofinder Feed'
                    && $context['namespace'] === 'none'
                    && isset($context['sample_title'], $context['sample_d_title']),
            );

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_logs_feed_structure_validation_for_google_namespace(): void
    {
        Http::fake([
            'https://example.com/feed' => Http::response($this->createGoogleNamespacedFeedXml()),
        ]);

        $this->mockStorage->shouldReceive('put')->once();

        // Validate debug log with ALL context items - catches RemoveArrayItem mutations
        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(
                static fn(string $message, array $context): bool => \str_contains($message, 'Feed structure validated')
                    && $context['service'] === 'Doofinder Feed'
                    && $context['namespace'] === 'g'
                    && isset($context['sample_title'], $context['sample_d_title']),
            );

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    #[Test]
    public function it_logs_meta_refresh_redirect_with_full_context(): void
    {
        $htmlWithRedirect = $this->createHtmlWithMetaRefresh('https://s3.example.com/feed.xml');

        Http::fake([
            'https://example.com/feed' => Http::response($htmlWithRedirect),
            'https://s3.example.com/feed.xml' => Http::response($this->createValidFeedXml()),
        ]);

        $this->mockStorage->shouldReceive('put')->once();

        // Validate ALL log context items - catches RemoveArrayItem mutations
        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => \str_contains($message, 'meta-refresh redirect')
                    && $context['service'] === 'Doofinder Feed'
                    && $context['original_url'] === 'https://example.com/feed'
                    && $context['redirect_url'] === 'https://s3.example.com/feed.xml');

        // Allow other debug calls
        $this->mockLogger->shouldReceive('debug')->byDefault();

        $this->processor->process('https://example.com/feed', 'feeds/output.xml');
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function createValidFeedXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
    <channel>
        <title>Test Store</title>
        <link>https://example.com/</link>
        <item>
            <link>https://example.com/product-1</link>
            <title>original title one</title>
            <g:price>29.99 GBP</g:price>
            <d_title>Display Title One</d_title>
        </item>
        <item>
            <link>https://example.com/product-2</link>
            <title>original title two</title>
            <g:price>49.99 GBP</g:price>
            <d_title>Display Title Two</d_title>
        </item>
    </channel>
</rss>
XML;
    }

    private function createFeedXmlWithEmptyDTitle(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
    <channel>
        <title>Test Store</title>
        <item>
            <title>original title one</title>
            <d_title>Display Title One</d_title>
        </item>
        <item>
            <title>original title two</title>
            <d_title>Display Title Two</d_title>
        </item>
        <item>
            <title>original title three</title>
            <d_title></d_title>
        </item>
    </channel>
</rss>
XML;
    }

    private function createGoogleNamespacedFeedXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">
    <channel>
        <title>Test Store</title>
        <item>
            <link>https://example.com/product-1</link>
            <g:title>original title one</g:title>
            <g:price>29.99 GBP</g:price>
            <g:d_title>Display Title One</g:d_title>
        </item>
        <item>
            <link>https://example.com/product-2</link>
            <g:title>original title two</g:title>
            <g:price>49.99 GBP</g:price>
            <g:d_title>Display Title Two</g:d_title>
        </item>
    </channel>
</rss>
XML;
    }

    private function createHtmlWithMetaRefresh(string $redirectUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8" />
        <meta http-equiv="refresh" content="0;url='{$redirectUrl}'" />
        <title>Redirecting</title>
    </head>
    <body>
        Redirecting...
    </body>
</html>
HTML;
    }
}
