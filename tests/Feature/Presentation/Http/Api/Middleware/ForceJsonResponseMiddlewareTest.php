<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Middleware;

use App\Presentation\Http\Api\Middleware\ForceJsonResponseMiddleware;
use Illuminate\Support\Facades\Route;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

#[CoversClass(ForceJsonResponseMiddleware::class)]
final class ForceJsonResponseMiddlewareTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/web/page', static fn(): Response => \response('HTML page', 200));
    }

    #[Test]
    public function api_request_without_accept_header_receives_json_response(): void
    {
        $response = $this->get('/api/products');

        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure(['error']);
    }

    #[Test]
    public function api_request_with_html_accept_header_is_overridden_to_json(): void
    {
        $response = $this->withHeaders(['Accept' => 'text/html'])->get('/api/products');

        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure(['error']);
    }

    #[Test]
    public function non_api_request_is_unaffected_by_middleware(): void
    {
        $response = $this->get('/_test/web/page');

        $response->assertStatus(200);
        $this->assertStringContainsString('HTML page', $response->getContent());
    }

    #[Test]
    public function routing_time_404_for_unmatched_api_url_returns_json(): void
    {
        $response = $this->get('/api/nonexistent-endpoint-that-does-not-exist');

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure(['error' => ['type', 'message']]);
        $response->assertJsonPath('error.type', 'not_found');
    }
}
