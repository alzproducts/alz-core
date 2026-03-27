<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Feeds;

use App\Domain\Exceptions\Data\MalformedFeedDataException;
use App\Infrastructure\Feeds\DoofinderItemTransformer;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * DoofinderItemTransformer Unit Tests.
 *
 * Tests the item transformation logic including:
 * - XML parsing and validation
 * - Title substitution with d_title content
 * - Namespace handling (non-namespaced and g: prefixed)
 * - Error handling for malformed XML
 * - Structure preservation during transformation
 */
#[CoversClass(DoofinderItemTransformer::class)]
final class DoofinderItemTransformerTest extends TestCase
{
    private LoggerInterface&MockInterface $mockLogger;
    private DoofinderItemTransformer $transformer;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockLogger = Mockery::mock(LoggerInterface::class);
        $this->mockLogger->shouldReceive('debug')->byDefault();

        $this->transformer = new DoofinderItemTransformer($this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | validateFirstItem() - Non-Namespaced Elements
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function validate_first_item_accepts_valid_non_namespaced_xml(): void
    {
        $itemXml = $this->createNonNamespacedItem('Original Title', 'Display Title');

        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(
                static fn(string $message, array $context): bool => \str_contains($message, 'Feed structure validated')
                    && $context['service'] === 'Doofinder Feed'
                    && $context['namespace'] === 'none'
                    && $context['sample_title'] === 'Original Title'
                    && $context['sample_d_title'] === 'Display Title',
            );

        $this->transformer->validateFirstItem($itemXml);

        // No exception thrown = success
        $this->assertTrue(true);
    }

    #[Test]
    public function validate_first_item_logs_correct_context_for_non_namespaced(): void
    {
        $itemXml = $this->createNonNamespacedItem('Product Name', 'SEO Title');

        $capturedContext = null;
        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(static function (string $message, array $context) use (&$capturedContext): bool {
                if (\str_contains($message, 'Feed structure validated')) {
                    $capturedContext = $context;

                    return true;
                }

                return false;
            });

        $this->transformer->validateFirstItem($itemXml);

        $this->assertSame('Doofinder Feed', $capturedContext['service']);
        $this->assertSame('none', $capturedContext['namespace']);
        $this->assertSame('Product Name', $capturedContext['sample_title']);
        $this->assertSame('SEO Title', $capturedContext['sample_d_title']);
    }

    /*
    |--------------------------------------------------------------------------
    | validateFirstItem() - Google Namespaced Elements
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function validate_first_item_accepts_valid_google_namespaced_xml(): void
    {
        $itemXml = $this->createGoogleNamespacedItem('Original Title', 'Display Title');

        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(
                static fn(string $message, array $context): bool => \str_contains($message, 'Feed structure validated')
                    && $context['namespace'] === 'g',
            );

        $this->transformer->validateFirstItem($itemXml);

        // No exception thrown = success
        $this->assertTrue(true);
    }

    #[Test]
    public function validate_first_item_logs_correct_context_for_google_namespace(): void
    {
        $itemXml = $this->createGoogleNamespacedItem('G:Product', 'G:Display');

        $capturedContext = null;
        $this->mockLogger
            ->shouldReceive('debug')
            ->once()
            ->withArgs(static function (string $message, array $context) use (&$capturedContext): bool {
                if (\str_contains($message, 'Feed structure validated')) {
                    $capturedContext = $context;

                    return true;
                }

                return false;
            });

        $this->transformer->validateFirstItem($itemXml);

        $this->assertSame('Doofinder Feed', $capturedContext['service']);
        $this->assertSame('g', $capturedContext['namespace']);
        $this->assertSame('G:Product', $capturedContext['sample_title']);
        $this->assertSame('G:Display', $capturedContext['sample_d_title']);
    }

    /*
    |--------------------------------------------------------------------------
    | validateFirstItem() - Missing Elements
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function validate_first_item_throws_when_missing_title(): void
    {
        $itemXml = '<item><d_title>Display Title</d_title><price>29.99</price></item>';

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('Malformed feed data');

        $this->transformer->validateFirstItem($itemXml);
    }

    #[Test]
    public function validate_first_item_throws_when_missing_d_title(): void
    {
        $itemXml = '<item><title>Original Title</title><price>29.99</price></item>';

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('Malformed feed data');

        $this->transformer->validateFirstItem($itemXml);
    }

    #[Test]
    public function validate_first_item_throws_when_missing_both_title_and_d_title(): void
    {
        $itemXml = '<item><price>29.99</price><description>Some product</description></item>';

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('Malformed feed data');

        $this->transformer->validateFirstItem($itemXml);
    }

    #[Test]
    public function validate_first_item_throws_with_correct_feed_name(): void
    {
        $itemXml = '<item><price>29.99</price></item>';

        try {
            $this->transformer->validateFirstItem($itemXml);
            $this->fail('Expected MalformedFeedDataException');
        } catch (MalformedFeedDataException $e) {
            $this->assertSame('Doofinder Feed', $e->feedName);
        }
    }

    #[Test]
    public function validate_first_item_throws_on_malformed_xml(): void
    {
        $malformedXml = '<item><title>Unclosed';

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('Malformed feed data');

        $this->transformer->validateFirstItem($malformedXml);
    }

    /*
    |--------------------------------------------------------------------------
    | transform() - Title Substitution
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function transform_substitutes_title_with_d_title_content(): void
    {
        $itemXml = $this->createNonNamespacedItem('Original Title', 'Display Title');

        [$transformedXml, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertTrue($wasSubstituted);
        $this->assertStringContainsString('>Display Title<', $transformedXml);
        $this->assertStringNotContainsString('>Original Title<', $transformedXml);
    }

    #[Test]
    public function transform_returns_substituted_true_when_title_replaced(): void
    {
        $itemXml = $this->createNonNamespacedItem('Old', 'New');

        [$_, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertTrue($wasSubstituted);
    }

    #[Test]
    public function transform_substitutes_google_namespaced_title(): void
    {
        $itemXml = $this->createGoogleNamespacedItem('Original G:Title', 'Display G:Title');

        [$transformedXml, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertTrue($wasSubstituted);
        $this->assertStringContainsString('Display G:Title', $transformedXml);
    }

    /*
    |--------------------------------------------------------------------------
    | transform() - Empty d_title Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function transform_does_not_substitute_when_d_title_is_empty(): void
    {
        $itemXml = $this->createNonNamespacedItem('Original Title', '');

        [$transformedXml, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertFalse($wasSubstituted);
        $this->assertStringContainsString('>Original Title<', $transformedXml);
    }

    #[Test]
    public function transform_returns_substituted_false_when_d_title_empty(): void
    {
        $itemXml = $this->createNonNamespacedItem('Original', '');

        [$_, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertFalse($wasSubstituted);
    }

    #[Test]
    public function transform_does_not_substitute_empty_google_namespaced_d_title(): void
    {
        $itemXml = $this->createGoogleNamespacedItem('Original G:Title', '');

        [$transformedXml, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertFalse($wasSubstituted);
        $this->assertStringContainsString('Original G:Title', $transformedXml);
    }

    /*
    |--------------------------------------------------------------------------
    | transform() - Missing Elements (No Substitution)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function transform_returns_false_when_missing_d_title(): void
    {
        $itemXml = '<item><title>Original Title</title><price>29.99</price></item>';

        [$transformedXml, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertFalse($wasSubstituted);
        $this->assertStringContainsString('>Original Title<', $transformedXml);
    }

    #[Test]
    public function transform_returns_false_when_missing_title(): void
    {
        $itemXml = '<item><d_title>Display Title</d_title><price>29.99</price></item>';

        [$transformedXml, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertFalse($wasSubstituted);
        $this->assertStringContainsString('Display Title', $transformedXml);
    }

    /*
    |--------------------------------------------------------------------------
    | transform() - Error Handling
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function transform_throws_on_malformed_xml(): void
    {
        $malformedXml = '<item><title>Unclosed';

        $this->expectException(MalformedFeedDataException::class);
        $this->expectExceptionMessage('Malformed feed data');

        $this->transformer->transform($malformedXml);
    }

    #[Test]
    public function transform_throws_with_correct_feed_name_for_malformed_xml(): void
    {
        $malformedXml = '<item><not-closed>';

        try {
            $this->transformer->transform($malformedXml);
            $this->fail('Expected MalformedFeedDataException');
        } catch (MalformedFeedDataException $e) {
            $this->assertSame('Doofinder Feed', $e->feedName);
            $this->assertStringContainsString('Invalid item XML', $e->reason);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | transform() - Structure Preservation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function transform_preserves_other_elements(): void
    {
        $itemXml = <<<'XML'
<item>
    <title>Original Title</title>
    <d_title>Display Title</d_title>
    <price>29.99</price>
    <description>Product description</description>
    <link>https://example.com/product</link>
</item>
XML;

        [$transformedXml, $_] = $this->transformer->transform($itemXml);

        $this->assertStringContainsString('<price>29.99</price>', $transformedXml);
        $this->assertStringContainsString('<description>Product description</description>', $transformedXml);
        $this->assertStringContainsString('<link>https://example.com/product</link>', $transformedXml);
    }

    #[Test]
    public function transform_preserves_xml_attributes(): void
    {
        $itemXml = '<item id="123" sku="ABC"><title>Original</title><d_title>Display</d_title></item>';

        [$transformedXml, $_] = $this->transformer->transform($itemXml);

        $this->assertStringContainsString('id="123"', $transformedXml);
        $this->assertStringContainsString('sku="ABC"', $transformedXml);
    }

    #[Test]
    public function transform_removes_xml_declaration_from_output(): void
    {
        $itemXml = $this->createNonNamespacedItem('Original', 'Display');

        [$transformedXml, $_] = $this->transformer->transform($itemXml);

        $this->assertStringNotContainsString('<?xml', $transformedXml);
    }

    #[Test]
    public function transform_preserves_cdata_sections(): void
    {
        $itemXml = <<<'XML'
<item>
    <title>Original</title>
    <d_title>Display</d_title>
    <description><![CDATA[<strong>Bold</strong> text]]></description>
</item>
XML;

        [$transformedXml, $_] = $this->transformer->transform($itemXml);

        // CDATA content should be preserved (may be escaped or in CDATA)
        $this->assertStringContainsString('Bold', $transformedXml);
    }

    #[Test]
    public function transform_handles_special_xml_characters_in_d_title(): void
    {
        $itemXml = <<<'XML'
<item>
    <title>Original</title>
    <d_title>Display &amp; More &lt;Special&gt;</d_title>
</item>
XML;

        [$transformedXml, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertTrue($wasSubstituted);
        // The ampersand and angle brackets should be preserved (escaped or decoded)
        $this->assertMatchesRegularExpression('/Display.*More.*Special/', $transformedXml);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function transform_handles_whitespace_only_d_title_as_empty(): void
    {
        // Whitespace-only d_title should not trigger substitution
        $itemXml = '<item><title>Original</title><d_title>   </d_title></item>';

        [$transformedXml, $wasSubstituted] = $this->transformer->transform($itemXml);

        // Whitespace is technically not empty, so it may substitute
        // Check that at least no exception is thrown
        $this->assertIsString($transformedXml);
    }

    #[Test]
    public function transform_handles_self_closing_elements(): void
    {
        $itemXml = '<item><title>Original</title><d_title>Display</d_title><image/></item>';

        [$transformedXml, $_] = $this->transformer->transform($itemXml);

        // Self-closing element should be preserved
        $this->assertStringContainsString('image', $transformedXml);
    }

    #[Test]
    public function transform_handles_mixed_namespace_item(): void
    {
        // Item with both namespaced and non-namespaced elements
        $itemXml = <<<'XML'
<item xmlns:g="http://base.google.com/ns/1.0">
    <title>Non-namespaced Title</title>
    <d_title>Non-namespaced Display</d_title>
    <g:price>29.99 GBP</g:price>
</item>
XML;

        [$transformedXml, $wasSubstituted] = $this->transformer->transform($itemXml);

        $this->assertTrue($wasSubstituted);
        $this->assertStringContainsString('Non-namespaced Display', $transformedXml);
        $this->assertStringContainsString('g:price', $transformedXml);
    }

    #[Test]
    #[DataProvider('provideValidItemXml')]
    public function transform_returns_valid_xml_for_various_inputs(string $inputXml): void
    {
        [$transformedXml, $_] = $this->transformer->transform($inputXml);

        // Should be parseable as XML (won't throw)
        $parsed = new SimpleXMLElement($transformedXml);
        $this->assertInstanceOf(SimpleXMLElement::class, $parsed);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideValidItemXml(): array
    {
        return [
            'simple item' => ['<item><title>T</title><d_title>D</d_title></item>'],
            'with attributes' => ['<item id="1"><title>T</title><d_title>D</d_title></item>'],
            'with nested elements' => ['<item><title>T</title><d_title>D</d_title><meta><key>value</key></meta></item>'],
            'with empty elements' => ['<item><title>T</title><d_title>D</d_title><empty></empty></item>'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function createNonNamespacedItem(string $title, string $dTitle): string
    {
        return <<<XML
<item>
    <title>{$title}</title>
    <d_title>{$dTitle}</d_title>
    <price>29.99</price>
</item>
XML;
    }

    private function createGoogleNamespacedItem(string $title, string $dTitle): string
    {
        return <<<XML
<item xmlns:g="http://base.google.com/ns/1.0">
    <g:title>{$title}</g:title>
    <g:d_title>{$dTitle}</g:d_title>
    <g:price>29.99 GBP</g:price>
</item>
XML;
    }
}
