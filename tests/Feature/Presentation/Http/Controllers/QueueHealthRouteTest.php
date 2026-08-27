<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Controllers;

use App\Presentation\Http\Controllers\QueueHealthController;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

#[CoversClass(QueueHealthController::class)]
final class QueueHealthRouteTest extends TestCase
{
    private const string USER = 'ops-user';

    private const string PASS = 'ops-password';

    private const string ENDPOINT = '/ops/queue-health';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // No HORIZON_* env under test: unconfigured credentials abort 500 outside the
        // local environment, which would mask the 401 path.
        Config::set('horizon.auth.username', self::USER);
        Config::set('horizon.auth.password', self::PASS);
    }

    /**
     * @return TestResponse<Response>
     */
    private function getQueueHealthAsOps(): TestResponse
    {
        return $this->withBasicAuth(self::USER, self::PASS)->get(self::ENDPOINT);
    }

    #[Test]
    public function request_without_credentials_is_unauthorized(): void
    {
        $response = $this->get(self::ENDPOINT);

        $response->assertUnauthorized();
        $response->assertHeader('WWW-Authenticate', 'Basic realm="Horizon Dashboard"');
    }

    #[Test]
    public function valid_credentials_return_queue_depths(): void
    {
        $response = $this->getQueueHealthAsOps();

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonStructure(['status', 'queues' => ['high', 'default', 'low'], 'total_depth']);
    }

    // bootstrap/app.php registers routes/web.php outside the 'web' middleware group, so these
    // routes are stateless. A Set-Cookie here means the group was reapplied.
    #[Test]
    public function queue_health_response_carries_no_cookies(): void
    {
        $response = $this->getQueueHealthAsOps();

        $response->assertHeaderMissing('Set-Cookie');
    }
}
