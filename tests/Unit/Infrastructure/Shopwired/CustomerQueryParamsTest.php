<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired;

use App\Infrastructure\Shopwired\Contracts\PaginatableQueryParams;
use App\Infrastructure\Shopwired\CustomerQueryParams;
use App\Infrastructure\Shopwired\ShopwiredQueryParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CustomerQueryParams Unit Tests.
 *
 * Tests the immutable value object for ShopWired Customer API query parameters.
 * Covers construction, fluent builders, pagination, and toArray output.
 */
#[CoversClass(CustomerQueryParams::class)]
final class CustomerQueryParamsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_params_with_defaults(): void
    {
        $params = new CustomerQueryParams();

        $this->assertNull($params->trade);
        $this->assertNull($params->email);
        $this->assertSame(50, $params->getCount());
    }

    #[Test]
    public function it_implements_paginatable_query_params(): void
    {
        $params = new CustomerQueryParams();

        $this->assertInstanceOf(PaginatableQueryParams::class, $params);
    }

    #[Test]
    public function it_creates_params_with_custom_base_params(): void
    {
        $baseParams = new ShopwiredQueryParams(count: 25, offset: 50);
        $params = new CustomerQueryParams(baseParams: $baseParams);

        $this->assertSame(25, $params->getCount());
    }

    #[Test]
    public function it_creates_params_with_trade_filter(): void
    {
        $params = new CustomerQueryParams(trade: true);

        $this->assertTrue($params->trade);
    }

    #[Test]
    public function it_creates_params_with_email_filter(): void
    {
        $params = new CustomerQueryParams(email: 'test@example.com');

        $this->assertSame('test@example.com', $params->email);
    }

    /*
    |--------------------------------------------------------------------------
    | forBulkFetch() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function for_bulk_fetch_creates_params_with_max_count(): void
    {
        $params = CustomerQueryParams::forBulkFetch();

        $this->assertSame(100, $params->getCount());
    }

    #[Test]
    public function for_bulk_fetch_has_no_trade_filter(): void
    {
        $params = CustomerQueryParams::forBulkFetch();

        $this->assertNull($params->trade);
    }

    /*
    |--------------------------------------------------------------------------
    | withTrade() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_trade_true_returns_new_instance(): void
    {
        $original = new CustomerQueryParams();
        $filtered = $original->withTrade(true);

        $this->assertNotSame($original, $filtered);
        $this->assertNull($original->trade);
        $this->assertTrue($filtered->trade);
    }

    #[Test]
    public function with_trade_false_sets_false_value(): void
    {
        $params = (new CustomerQueryParams())->withTrade(false);

        $this->assertFalse($params->trade);
    }

    #[Test]
    public function with_trade_null_clears_filter(): void
    {
        $params = (new CustomerQueryParams())
            ->withTrade(true)
            ->withTrade(null);

        $this->assertNull($params->trade);
    }

    #[Test]
    public function with_trade_preserves_email_filter(): void
    {
        $params = (new CustomerQueryParams(email: 'test@example.com'))
            ->withTrade(true);

        $this->assertSame('test@example.com', $params->email);
        $this->assertTrue($params->trade);
    }

    /*
    |--------------------------------------------------------------------------
    | withEmail() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_email_returns_new_instance(): void
    {
        $original = new CustomerQueryParams();
        $filtered = $original->withEmail('test@example.com');

        $this->assertNotSame($original, $filtered);
        $this->assertNull($original->email);
        $this->assertSame('test@example.com', $filtered->email);
    }

    #[Test]
    public function with_email_null_clears_filter(): void
    {
        $params = (new CustomerQueryParams())
            ->withEmail('test@example.com')
            ->withEmail(null);

        $this->assertNull($params->email);
    }

    #[Test]
    public function with_email_preserves_trade_filter(): void
    {
        $params = (new CustomerQueryParams(trade: true))
            ->withEmail('test@example.com');

        $this->assertTrue($params->trade);
        $this->assertSame('test@example.com', $params->email);
    }

    /*
    |--------------------------------------------------------------------------
    | withCount() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_count_returns_new_instance(): void
    {
        $original = new CustomerQueryParams();
        $modified = $original->withCount(25);

        $this->assertNotSame($original, $modified);
        $this->assertSame(50, $original->getCount());
        $this->assertSame(25, $modified->getCount());
    }

    #[Test]
    public function with_count_preserves_filters(): void
    {
        $params = (new CustomerQueryParams(trade: true, email: 'test@example.com'))
            ->withCount(10);

        $this->assertTrue($params->trade);
        $this->assertSame('test@example.com', $params->email);
        $this->assertSame(10, $params->getCount());
    }

    /*
    |--------------------------------------------------------------------------
    | withOffset() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_offset_returns_new_instance(): void
    {
        $original = new CustomerQueryParams();
        $modified = $original->withOffset(100);

        $this->assertNotSame($original, $modified);
    }

    #[Test]
    public function with_offset_preserves_filters(): void
    {
        $params = (new CustomerQueryParams(trade: true, email: 'test@example.com'))
            ->withOffset(50);

        $this->assertTrue($params->trade);
        $this->assertSame('test@example.com', $params->email);
    }

    /*
    |--------------------------------------------------------------------------
    | withBaseParams() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function with_base_params_returns_new_instance(): void
    {
        $original = new CustomerQueryParams();
        $newBase = ShopwiredQueryParams::forBulkFetch()->withEmbeds(['country']);
        $modified = $original->withBaseParams($newBase);

        $this->assertNotSame($original, $modified);
        $this->assertSame(100, $modified->getCount());
    }

    #[Test]
    public function with_base_params_preserves_filters(): void
    {
        $params = (new CustomerQueryParams(trade: true, email: 'test@example.com'))
            ->withBaseParams(ShopwiredQueryParams::forBulkFetch());

        $this->assertTrue($params->trade);
        $this->assertSame('test@example.com', $params->email);
    }

    /*
    |--------------------------------------------------------------------------
    | nextPage() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function next_page_returns_new_instance(): void
    {
        $original = new CustomerQueryParams();
        $next = $original->nextPage();

        $this->assertNotSame($original, $next);
    }

    #[Test]
    public function next_page_advances_offset(): void
    {
        $params = (new CustomerQueryParams())->withCount(25);
        $this->assertTrue($params->isFirstPage());

        $next = $params->nextPage();
        $this->assertFalse($next->isFirstPage());
    }

    #[Test]
    public function next_page_preserves_filters(): void
    {
        $params = (new CustomerQueryParams(trade: true, email: 'test@example.com'))
            ->nextPage();

        $this->assertTrue($params->trade);
        $this->assertSame('test@example.com', $params->email);
    }

    #[Test]
    public function next_page_accumulates_offset(): void
    {
        $params = (new CustomerQueryParams())->withCount(10);

        $page1 = $params;
        $page2 = $page1->nextPage();
        $page3 = $page2->nextPage();

        $this->assertTrue($page1->isFirstPage());
        $this->assertFalse($page2->isFirstPage());
        $this->assertFalse($page3->isFirstPage());
    }

    /*
    |--------------------------------------------------------------------------
    | isFirstPage() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_first_page_returns_true_for_new_params(): void
    {
        $params = new CustomerQueryParams();

        $this->assertTrue($params->isFirstPage());
    }

    #[Test]
    public function is_first_page_returns_false_after_next_page(): void
    {
        $params = (new CustomerQueryParams())->nextPage();

        $this->assertFalse($params->isFirstPage());
    }

    /*
    |--------------------------------------------------------------------------
    | toArray() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_includes_base_params(): void
    {
        $params = new CustomerQueryParams();
        $array = $params->toArray();

        $this->assertArrayHasKey('count', $array);
        $this->assertArrayHasKey('offset', $array);
        $this->assertSame(50, $array['count']);
        $this->assertSame(0, $array['offset']);
    }

    #[Test]
    public function to_array_excludes_trade_when_null(): void
    {
        $params = new CustomerQueryParams();
        $array = $params->toArray();

        $this->assertArrayNotHasKey('trade', $array);
    }

    #[Test]
    public function to_array_includes_trade_as_one_when_true(): void
    {
        $params = (new CustomerQueryParams())->withTrade(true);
        $array = $params->toArray();

        $this->assertArrayHasKey('trade', $array);
        $this->assertSame('1', $array['trade']);
    }

    #[Test]
    public function to_array_includes_trade_as_zero_when_false(): void
    {
        $params = (new CustomerQueryParams())->withTrade(false);
        $array = $params->toArray();

        $this->assertArrayHasKey('trade', $array);
        $this->assertSame('0', $array['trade']);
    }

    #[Test]
    public function to_array_excludes_email_when_null(): void
    {
        $params = new CustomerQueryParams();
        $array = $params->toArray();

        $this->assertArrayNotHasKey('email', $array);
    }

    #[Test]
    public function to_array_includes_email_when_set(): void
    {
        $params = (new CustomerQueryParams())->withEmail('test@example.com');
        $array = $params->toArray();

        $this->assertArrayHasKey('email', $array);
        $this->assertSame('test@example.com', $array['email']);
    }

    #[Test]
    public function to_array_includes_embeds_from_base_params(): void
    {
        $baseParams = (new ShopwiredQueryParams())->withEmbeds(['country', 'state']);
        $params = (new CustomerQueryParams())->withBaseParams($baseParams);
        $array = $params->toArray();

        $this->assertArrayHasKey('embed', $array);
        $this->assertSame('country,state', $array['embed']);
    }

    #[Test]
    public function to_array_combines_all_parameters(): void
    {
        $baseParams = (new ShopwiredQueryParams())
            ->withCount(25)
            ->withOffset(50)
            ->withEmbeds(['country']);

        $params = (new CustomerQueryParams())
            ->withBaseParams($baseParams)
            ->withTrade(true)
            ->withEmail('test@example.com');

        $array = $params->toArray();

        $this->assertSame(25, $array['count']);
        $this->assertSame(50, $array['offset']);
        $this->assertSame('country', $array['embed']);
        $this->assertSame('1', $array['trade']);
        $this->assertSame('test@example.com', $array['email']);
    }
}
